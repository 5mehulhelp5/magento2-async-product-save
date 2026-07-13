<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Queue\Processor;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedType;
use Magento\GroupedProduct\Model\ResourceModel\Product\Link as GroupedLink;
use Magento\Catalog\Model\ResourceModel\Product\Link as LinkResource;
use Mohan\ProductQueueSave\Logger\Logger;

/**
 * After productRepository->save() the SaveHandler deleted+re-inserted all
 * grouped links via ProductLinkRepository::save().  However, that path calls
 * DataObjectProcessor::buildOutputDataArray() which silently skips null return
 * values — so qty never makes it into catalog_product_link_attribute_decimal.
 *
 * Fix: call linkResource->saveProductLinks() directly after the main save
 * to write (or overwrite) the qty values using the raw data from the payload.
 */
class GroupedProcessor
{
    public function __construct(
        private readonly LinkResource    $linkResource,
        private readonly MetadataPool   $metadataPool,
        private readonly Logger         $logger
    ) {}

    /**
     * @param ProductInterface|Product $savedProduct  The product returned by productRepository->save()
     * @param array                    $productData   Full queue payload (same array passed to doSave)
     */
    public function process(ProductInterface $savedProduct, array $productData): void
    {
        if ($savedProduct->getTypeId() !== GroupedType::TYPE_CODE) {
            return;
        }

        if (empty($productData['links_managed'])) {
            return;
        }

        $rawAssociated = $productData['links']['associated'] ?? [];
        if (empty($rawAssociated)) {
            return;
        }

        // Build the $data array that saveProductLinks expects:
        // [$linkedProductId => ['qty' => float, 'position' => int, ...]]
        $linksData = [];
        foreach ($rawAssociated as $linkRaw) {
            if (!empty($linkRaw['delete']) || !empty($linkRaw['_delete'])) {
                continue;
            }
            $linkedProductId = (int) ($linkRaw['id'] ?? $linkRaw['product_id'] ?? 0);
            if (!$linkedProductId) {
                continue;
            }
            $linksData[$linkedProductId] = [
                'qty'      => (float) ($linkRaw['qty'] ?? 1),
                'position' => (int)   ($linkRaw['position'] ?? 0),
            ];
        }

        if (empty($linksData)) {
            $this->logger->info('GroupedProcessor: no associated links to fix', [
                'product_id' => $savedProduct->getId(),
            ]);
            return;
        }

        try {
            // getLinkField() = 'row_id' (EE) or 'entity_id' (CE)
            $linkField  = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
            $parentId   = (int) $savedProduct->getData($linkField);

            $this->linkResource->saveProductLinks(
                $parentId,
                $linksData,
                GroupedLink::LINK_TYPE_GROUPED
            );

            $this->logger->info('GroupedProcessor: qty fix applied', [
                'parent_id'   => $parentId,
                'link_count'  => count($linksData),
                'links'       => $linksData,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('GroupedProcessor error: ' . $e->getMessage(), [
                'product_id' => $savedProduct->getId(),
                'trace'      => $e->getTraceAsString(),
            ]);
        }
    }
}
