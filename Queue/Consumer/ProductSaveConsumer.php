<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Queue\Consumer;

use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Mohan\ProductQueueSave\Api\Data\QueueLogInterface;
use Mohan\ProductQueueSave\Helper\Config;
use Mohan\ProductQueueSave\Logger\Logger;
use Mohan\ProductQueueSave\Model\Queue\Log;
use Mohan\ProductQueueSave\Model\Queue\LogFactory;
use Mohan\ProductQueueSave\Model\ResourceModel\Queue\Log as LogResource;
use Mohan\ProductQueueSave\Queue\Processor\BundleProcessor;
use Mohan\ProductQueueSave\Queue\Processor\DownloadableProcessor;
use Mohan\ProductQueueSave\Queue\Processor\GroupedProcessor;
use Mohan\ProductQueueSave\Queue\Processor\ImageProcessor;
use Mohan\ProductQueueSave\Queue\Processor\TierPriceProcessor;
use Mohan\ProductQueueSave\Queue\Processor\LinkProcessor;
use Mohan\ProductQueueSave\Queue\Processor\CustomOptionProcessor;
use Mohan\ProductQueueSave\Queue\Processor\ConfigurableProcessor;
use Mohan\ProductQueueSave\Queue\Processor\StockProcessor;
use Magento\Catalog\Model\ResourceModel\Product\CategoryLink as CategoryLinkResource;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\View\DesignInterface;

/**
 * Class ProductSaveConsumer
 *
 * Consumes messages from the product save queue and performs the actual save
 * using the same data that would have been used during a standard admin save.
 */
class ProductSaveConsumer
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductFactory $productFactory,
        private readonly LogFactory $logFactory,
        private readonly LogResource $logResource,
        private readonly ImageProcessor $imageProcessor,
        private readonly TierPriceProcessor $tierPriceProcessor,
        private readonly LinkProcessor $linkProcessor,
        private readonly CustomOptionProcessor $customOptionProcessor,
        private readonly ConfigurableProcessor $configurableProcessor,
        private readonly StockProcessor $stockProcessor,
        private readonly BundleProcessor $bundleProcessor,
        private readonly GroupedProcessor $groupedProcessor,
        private readonly DownloadableProcessor $downloadableProcessor,
        private readonly \Magento\Catalog\Model\Product\Gallery\Processor $galleryProcessor,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly State $appState,
        private readonly DataObjectHelper $dataObjectHelper,
        private readonly SerializerInterface $serializer,
        private readonly DataObjectFactory $dataObjectFactory,
        private readonly CategoryLinkResource $categoryLinkResource
    ) {}

    public function process(string $messageJson): void
    {
        $this->logger->info("ProductSaveConsumer started processing message");
        try {
            $data = $this->serializer->unserialize($messageJson);
            /** @var \Magento\Framework\DataObject $message */
            $message = $this->dataObjectFactory->create(['data' => $data]);

            $log = $this->createLog($message);

            $this->appState->emulateAreaCode(Area::AREA_ADMINHTML, function () use ($message, $log) {
                // Initialize design theme to Backend (standard for Catalog operations)
                $design = ObjectManager::getInstance()->get(DesignInterface::class);
                $design->setDesignTheme('Magento/backend', Area::AREA_ADMINHTML);
                
                $this->doSave($message, $log);
            });
        } catch (\Throwable $e) {
            $context = [
                'json' => $messageJson,
                'trace' => $e->getTraceAsString()
            ];
            $prev = $e->getPrevious();
            if ($prev) {
                $context['previous_msg'] = $prev->getMessage();
                $context['previous_trace'] = $prev->getTraceAsString();
            }

            $this->logger->critical('Product queue consumer error: ' . $e->getMessage(), $context);

            // If we have a message object and a log, handle the failure gracefully
            if (isset($message) && isset($log)) {
                $this->handleFailure($message, $log, $e);
            }

            throw $e;
        }
    }


    /**
     * Perform the full product save sequence.
     */
    private function doSave(DataObject $message, Log $log): void
    {
        $productData = $message->getProductData();
        $productId   = $message->getProductId();
        $storeId     = $message->getStoreId();

        $this->updateLog($log, QueueLogInterface::STATUS_PROCESSING);

        // ── 1. Load or create the product ────────────────────────────────────
        if ($productId) {
            try {
                /** @var Product $product */
                $product = $this->productRepository->getById(
                    $productId,
                    true,
                    $storeId ?: Store::DEFAULT_STORE_ID
                );
            } catch (NoSuchEntityException $e) {
                throw new LocalizedException(__('Product with ID %1 not found.', $productId));
            }
        } else {
            /** @var Product $product */
            $product = $this->productFactory->create();
            $sku = $productData['product']['sku'] ?? '';
            $typeId = $productData['product']['type_id'] ?? 'simple';
            
            // Fallback for new configurable products if type_id is missing or 'simple'
            $matrix = $productData['configurable-matrix'] ?? null;
            if ($typeId === 'simple' && !empty($matrix) && $matrix !== '[]') {
                $typeId = Configurable::TYPE_CODE;
            }
            
            $attributeSetId = $productData['product']['attribute_set_id'] ?? 4;
            
            $product->setStoreId($storeId ?: Store::DEFAULT_STORE_ID);
            $product->setSku($sku);
            $product->setTypeId($typeId);
            $product->setAttributeSetId($attributeSetId);
            
            $this->logger->info("Initializing new product", [
                'sku' => $sku,
                'type_id' => $typeId,
                'attribute_set_id' => $attributeSetId
            ]);
        }

        // ── 2. Apply core product attributes ─────────────────────────────────
        $rawProduct = $productData['product'] ?? [];
        if ($product->isObjectNew() && isset($typeId) && $typeId === Configurable::TYPE_CODE) {
            $rawProduct['type_id'] = Configurable::TYPE_CODE;
        }

        // Strip fields that are handled separately or must not be set here.
        // Image role fields (image, small_image, thumbnail, swatch_image) may contain
        // '.tmp' paths from the admin form. Setting a .tmp path on the product directly
        // causes MediaGalleryProcessor to call Gallery\Processor::addImage() on a path
        // that doesn't exist yet and throw "The image doesn't exist". ImageProcessor
        // handles new (.tmp) images and will assign their roles after staging.
        // Non-.tmp values are existing image file paths (e.g. '/g/c/filename.jpg') and
        // MUST be kept — they represent role changes for already-persisted images.
        $imageRoleKeys = ['image', 'small_image', 'thumbnail', 'swatch_image'];
        unset(
            $rawProduct['media_gallery'],
            $rawProduct['tier_price'],
            $rawProduct['stock_data'],
            $rawProduct['quantity_and_stock_status'],
            $rawProduct['options'],
            $rawProduct['affect_configurable_product_attributes']
        );
        foreach ($imageRoleKeys as $roleKey) {
            $roleValue = $rawProduct[$roleKey] ?? null;
            if ($roleValue !== null && str_ends_with((string)$roleValue, '.tmp')) {
                unset($rawProduct[$roleKey]);
            }
        }
        $product->addData($rawProduct);

        // Website & category assignments
        // Use array_key_exists so an empty array (all websites unchecked) still calls setWebsiteIds.
        if (array_key_exists('website_ids', $productData)) {
            $product->setWebsiteIds($productData['website_ids'] ?? []);
        }
        // Use array_key_exists so an empty array (all categories removed) still calls setCategoryIds.
        // !empty() would silently skip the call and leave existing categories in place.
        $incomingCategoryIds = $productData['category_ids'] ?? null;
        $this->logger->info('Category IDs from queue payload', [
            'product_id'        => $productId,
            'payload_key_exists' => array_key_exists('category_ids', $productData),
            'payload_value'     => $incomingCategoryIds,
            'db_category_ids'   => $product->getCategoryIds(),
        ]);
        if (array_key_exists('category_ids', $productData)) {
            $product->setCategoryIds($incomingCategoryIds ?? []);
        }

        // ── 3-6. Process auxiliary data ──────────────────────────────────────
        $this->stockProcessor->process($product, $productData['stock_data'] ?? []);
        $this->tierPriceProcessor->process($product, $productData['tier_price'] ?? []);
        $this->linkProcessor->process($product, $productData);
        $this->customOptionProcessor->process($product, $productData['options'] ?? []);
        $this->bundleProcessor->process($product, $productData);

        // ── 7. Handle Gallery (Deletions & Additions) ───────────────────────

        // Ensure core gallery data is loaded so removeImage() can find entries.
        $dbGallery = $product->getMediaGallery('images') ?: [];

        // Build value_id → file map from the current DB gallery.
        $dbImagesByValueId = [];
        foreach ($dbGallery as $dbImg) {
            if (!empty($dbImg['value_id'])) {
                $dbImagesByValueId[(string)$dbImg['value_id']] = $dbImg['file'] ?? null;
            }
        }

        // Primary removal path: gallery_remove_ids sent as a separate POST field.
        // This is independent of the rawData payload, which may be stale (populated
        // at page-load and not updated when images are added by subsequent saves).
        $removalsCount = 0;
        $rawRemoveIds  = $productData['gallery_remove_ids'] ?? '';
        if (!empty($rawRemoveIds)) {
            $removeValueIds = array_filter(array_map('trim', explode(',', $rawRemoveIds)));
            foreach ($removeValueIds as $vid) {
                if (!isset($dbImagesByValueId[$vid]) || $dbImagesByValueId[$vid] === null) {
                    $this->logger->warning("Gallery remove: value_id $vid not found in current DB gallery");
                    continue;
                }
                $file = $dbImagesByValueId[$vid];
                $this->galleryProcessor->removeImage($product, $file);
                $removalsCount++;
                $this->logger->info("Gallery: marked for removal", ['value_id' => $vid, 'file' => $file]);
            }
        }

        // Fallback: also honour removed:1 flags in the queued media_gallery payload
        // (used when rawData value_ids happen to still match the current DB state).
        $queueGallery = $productData['product']['media_gallery']['images'] ?? [];
        foreach ($queueGallery as $image) {
            if (!empty($image['removed']) && !empty($image['value_id']) && !empty($image['file'])) {
                $vid = (string)$image['value_id'];
                if (isset($dbImagesByValueId[$vid])) {
                    $this->galleryProcessor->removeImage($product, $image['file']);
                    $removalsCount++;
                    $this->logger->info("Gallery: marked for removal (fallback)", [
                        'value_id' => $vid, 'file' => $image['file'],
                    ]);
                }
            }
        }

        if ($removalsCount > 0) {
            $this->logger->info("Gallery: total images marked for removal", ['count' => $removalsCount]);
        }

        // Let ImageProcessor call Gallery\Processor::addImage() for each source image.
        // addImage() reads the file content, base64-encodes it, and appends a gallery
        // entry WITH a 'content' field — exactly what processEntries() requires.
        $this->imageProcessor->process($product, $productData['images'] ?? []);

        // ── 7.1 Sanitize Gallery Data for Save ──────────────────────────────
        //
        // The ONLY entries that must be dropped are raw .tmp stubs: form inputs
        // that have no 'content' field and would throw "image content invalid"
        // inside processEntries(). All other entries must be passed through:
        //
        //   (a) removal entries  (value_id set, removed = "1")               keep
        //   (b) new content entries  (value_id unset, has 'content' field)   keep
        //   (c) existing DB images  (value_id set, not removed)              keep ← MUST keep
        //   (d) raw .tmp stub entries  (value_id unset, file ends .tmp)      drop
        //
        // Rule (c) must NOT be dropped. Magento's UpdateHandler interprets the
        // submitted images array as the full authoritative list. Any image NOT in
        // that list may be deleted (directly or via plugins). Passing all existing
        // images lets UpdateHandler explicitly know which ones to keep.
        $finalGalleryData = $product->getData('media_gallery');
        $hasGalleryChanges = false;
        if (is_array($finalGalleryData) && isset($finalGalleryData['images'])) {
            $cleanedImages = [];
            foreach ($finalGalleryData['images'] as $key => $image) {
                $file      = $image['file'] ?? '';
                $valueId   = $image['value_id'] ?? '';
                $isRemoved = !empty($image['removed']);

                // (d) Drop raw .tmp stub entries — no 'content', cause "image content invalid".
                if (!$isRemoved && empty($valueId) && str_ends_with($file, '.tmp')) {
                    $this->logger->warning("Gallery: Dropping un-staged .tmp entry", ['file' => $file]);
                    continue;
                }

                // Track whether anything meaningful is happening (removal or new image).
                if ($isRemoved || (empty($valueId) && !empty($image['content']))) {
                    $hasGalleryChanges = true;
                }

                $image['media_type'] = $image['media_type'] ?? 'image';
                $image['label']      = $image['label'] ?? '';
                $image['position']   = $image['position'] ?? 0;
                $image['disabled']   = $image['disabled'] ?? 0;
                $cleanedImages[$key] = $image;
            }

            if ($hasGalleryChanges && !empty($cleanedImages)) {
                $product->setData('media_gallery', ['images' => $cleanedImages]);
                $this->logger->info("Gallery sanitizer: passing to save", [
                    'total'         => count($cleanedImages),
                    'removal_count' => count(array_filter($cleanedImages, fn($i) => !empty($i['removed']))),
                    'new_count'     => count(array_filter($cleanedImages, fn($i) => empty($i['value_id']) && !empty($i['content']))),
                ]);
            } else {
                $product->unsetData('media_gallery');
                $this->logger->info("Gallery sanitizer: no gallery changes to save");
            }
        }

        // Log role values being applied to the product.
        $appliedRoles = [];
        foreach ($imageRoleKeys as $roleKey) {
            $val = $product->getData($roleKey);
            if ($val !== null && $val !== '') {
                $appliedRoles[$roleKey] = $val;
            }
        }
        if (!empty($appliedRoles)) {
            $this->logger->info("Image roles being saved", $appliedRoles);
        }


        // ── 7.2 Downloadable links & samples ────────────────────────────
        // Must run BEFORE productRepository->save() so extension attributes are
        // visible to Link\UpdateHandler and Sample\UpdateHandler.
        $this->downloadableProcessor->process($product, $productData);

        // ── 8. Single Repository Save ────────────────────────────────────────
        // Safeguard for empty price on configurable products
        if ($product->getTypeId() === Configurable::TYPE_CODE && ($product->getPrice() === null || $product->getPrice() === '')) {
            $product->setPrice(0);
        }

        $this->logger->info('Category IDs on product just before repository save', [
            'product_id'   => $productId,
            'category_ids' => $product->getCategoryIds(),
        ]);

        $savedProduct = $this->productRepository->save($product);

        // ── 9. Grouped product qty fix ───────────────────────────────────────
        // SaveHandler re-inserts grouped links but loses qty via the extension
        // attribute path (DataObjectProcessor skips null getQty()). Fix it
        // by calling saveProductLinks directly with the raw qty from the payload.
        $this->groupedProcessor->process($savedProduct, $productData);

        // ── 10. Configurable associations ────────────────────────────────────
        if ($savedProduct->getTypeId() === Configurable::TYPE_CODE) {
            $this->configurableProcessor->process($savedProduct, $productData);
        }

        // ── 11. Fix category links (applied after ALL saves) ──────────────────
        // Both productRepository->save() calls (here and inside ConfigurableProcessor)
        // use SaveHandler::mergeCategoryLinks() which merges stale DTO category_links
        // (from ReadHandler) back with model category_ids — re-adding any removed
        // category. We fix this once at the end by writing the authoritative set
        // directly to the DB, bypassing the merge entirely.
        if (array_key_exists('category_ids', $productData)) {
            $desired = array_map('intval', $incomingCategoryIds ?? []);

            $existing = $this->categoryLinkResource->getCategoryLinks($savedProduct);
            $existingPos = array_column($existing, 'position', 'category_id');

            $links = array_map(
                fn(int $id) => ['category_id' => $id, 'position' => (int)($existingPos[$id] ?? 0)],
                $desired
            );
            $this->categoryLinkResource->saveCategoryLinks($savedProduct, $links);

            $this->logger->info('Category links final sync after all saves', [
                'product_id'   => $productId,
                'category_ids' => $desired,
            ]);
        }

        $this->updateLog(
            $log,
            QueueLogInterface::STATUS_SUCCESS,
            null,
            (int) $savedProduct->getId(),
            $savedProduct->getSku()
        );

        $this->logger->info('Product saved successfully via queue.', [
            'message_id' => $message->getMessageId(),
            'product_id' => $savedProduct->getId(),
            'sku'        => $savedProduct->getSku(),
        ]);
    }

    /**
     * Handle a processing failure: log it and optionally retry.
     */
    private function handleFailure(
        DataObject $message,
        Log $log,
        \Throwable $e
    ): void {
        $retryCount   = $message->getData('retry_count') ?? 0;
        $maxRetries   = $this->config->getRetryAttempts();

        $this->logger->critical('Product queue save failed.', [
            'message_id'  => $message->getMessageId(),
            'product_id'  => $message->getProductId(),
            'retry_count' => $retryCount,
            'trace'       => $e->getTraceAsString(),
        ]);

        if ($retryCount < $maxRetries) {
            $this->updateLog($log, QueueLogInterface::STATUS_RETRY, $e->getMessage());
        } else {
            $this->updateLog($log, QueueLogInterface::STATUS_FAILED, $e->getMessage());
        }
        // Caller (process()) re-throws the original exception — don't throw here.
    }

    /**
     * Create an initial log record for this queue message.
     */
    private function createLog(DataObject $message): Log
    {
        /** @var Log $log */
        $log = $this->logFactory->create();
        
        // Try to find if log already exists for this message ID
        try {
            $this->logResource->load($log, $message->getMessageId(), 'message_id');
        } catch (\Exception $e) {
            // New log
        }

        $log->setMessageId($message->getMessageId())
            ->setProductId($message->getProductId())
            ->setStoreId($message->getStoreId())
            ->setAdminUserId($message->getAdminUserId())
            ->setRetryCount($message->getRetryCount())
            ->setStatus(QueueLogInterface::STATUS_PENDING);
 
        try {
            $this->logResource->save($log);
        } catch (\Exception $e) {
            $this->logger->error('Could not create queue log entry: ' . $e->getMessage());
        }
 
        return $log;
    }

    /**
     * Update an existing log record.
     */
    private function updateLog(
        Log $log,
        string $status,
        ?string $errorMessage = null,
        ?int $productId = null,
        ?string $sku = null
    ): void {
        $log->setStatus($status);
        if ($errorMessage !== null) {
            $log->setErrorMessage($errorMessage);
        }
        if ($productId !== null) {
            $log->setProductId($productId);
        }
        if ($sku !== null) {
            $log->setSku($sku);
        }
        if (in_array($status, [QueueLogInterface::STATUS_SUCCESS, QueueLogInterface::STATUS_FAILED], true)) {
            $log->setProcessedAt(date('Y-m-d H:i:s'));
        }

        try {
            $this->logResource->save($log);
        } catch (\Exception $e) {
            $this->logger->error('Could not update queue log: ' . $e->getMessage());
        }
    }
}
