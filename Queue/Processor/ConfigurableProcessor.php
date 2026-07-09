<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Queue\Processor;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\ConfigurableProduct\Api\Data\OptionInterface;
use Magento\ConfigurableProduct\Api\Data\OptionValueInterfaceFactory;
use Magento\ConfigurableProduct\Helper\Product\Options\Factory as ConfigurableOptionsFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Mohan\ProductQueueSave\Logger\Logger;

/**
 * Class ConfigurableProcessor
 *
 * Handles configurable product attribute options and child associations.
 */
class ConfigurableProcessor
{
    public function __construct(
        private readonly ConfigurableOptionsFactory $configurableOptionsFactory,
        private readonly ConfigurableResource $configurableResource,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly OptionValueInterfaceFactory $optionValueFactory,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ProductAction $productAction,
        private readonly ProductResource $productResource,
        private readonly ProductFactory $productFactory,
        private readonly Filesystem $filesystem,
        private readonly ImageProcessor $imageProcessor,
        private readonly Logger $logger
    ) {}

    /**
     * @param ProductInterface|Product $product
     * @param array $productData
     */
    public function process(ProductInterface $product, array $productData): void
    {
        $this->logger->info("ConfigurableProcessor::process start", [
            'sku'          => $product->getSku(),
            'product_id'   => $product->getId(),
            'keys'         => array_keys($productData),
            'has_matrix'   => isset($productData['configurable-matrix']),
            'has_assoc_ids' => isset($productData['associated_product_ids'])
        ]);
        try {
            // 1. Apply configurable attribute options in-memory (no save yet).
            $this->processAttributes($product, $productData);

            // 2. Create/sync child products and return their IDs.
            $childIds = $this->processAssociatedProducts($product, $productData);

            // 3. Attach child links in-memory on the same $product object.
            if (!empty($childIds)) {
                $extensionAttributes = $product->getExtensionAttributes();
                $extensionAttributes->setConfigurableProductLinks($childIds);
                $product->setExtensionAttributes($extensionAttributes);

                $this->logger->info("ConfigurableProcessor: Linking child IDs to parent", [
                    'parent_id'   => $product->getId(),
                    'parent_sku'  => $product->getSku(),
                    'child_ids'   => $childIds,
                ]);
            }

            // 4. ONE save: persists configurable options (catalog_product_super_attribute)
            //    AND child links (catalog_product_super_link) together.
            $this->productRepository->save($product);

            $this->logger->info("ConfigurableProcessor: Parent saved with options and links", [
                'parent_id'   => $product->getId(),
                'child_count' => count($childIds),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('ConfigurableProcessor error: ' . $e->getMessage(), [
                'product_id' => $product->getId(),
                'trace'      => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Set configurable attribute options on the product extension attributes.
     */
    private function processAttributes(Product $product, array $productData): void
    {
        $attributesDataRaw = $productData['configurable_attributes_data'] ?? '{}';
        if (is_string($attributesDataRaw)) {
            $attributesData = json_decode($attributesDataRaw, true) ?? [];
        } else {
            $attributesData = (array) $attributesDataRaw;
        }

        if (empty($attributesData)) {
            return;
        }

        $options = [];
        foreach ($attributesData as $attrData) {
            $values = [];
            foreach ($attrData['values'] ?? [] as $valueData) {
                $value = $this->optionValueFactory->create();
                $value->setValueIndex((int) $valueData['value_index']);
                if (isset($valueData['include'])) {
                    $value->setIsPercent(false);
                    $value->setPricingValue(0);
                }
                $values[] = $value;
            }

            $option = [
                'attribute_id'   => $attrData['attribute_id'],
                'code'           => $attrData['code'] ?? '',
                'label'          => $attrData['label'] ?? '',
                'position'       => $attrData['position'] ?? 0,
                'values'         => $values,
            ];
            $options[] = $option;
        }

        if (!empty($options)) {
            $configurableOptions = $this->configurableOptionsFactory->create($options);
            $extensionAttributes = $product->getExtensionAttributes();
            $extensionAttributes->setConfigurableProductOptions($configurableOptions);
            $product->setExtensionAttributes($extensionAttributes);
        }
    }

    /**
     * Create / sync all child simple products and return their entity IDs.
     *
     * @return int[]  De-duplicated, non-zero child product IDs.
     */
    private function processAssociatedProducts(Product $product, array $productData): array
    {
        $variationsMatrixRaw = $productData['variations-matrix'] ?? '{}';
        if (is_string($variationsMatrixRaw)) {
            $variationsMatrix = json_decode($variationsMatrixRaw, true) ?? [];
        } else {
            $variationsMatrix = (array) $variationsMatrixRaw;
        }

        // Also check configurable_products_data for existing child IDs
        $configurableProductsRaw = $productData['configurable_products_data'] ?? '{}';
        if (is_string($configurableProductsRaw)) {
            $configurableProductsData = json_decode($configurableProductsRaw, true) ?? [];
        } else {
            $configurableProductsData = (array) $configurableProductsRaw;
        }

        $childIds = [];

        // From variations matrix (new variations created during save)
        foreach ($variationsMatrix as $childSku => $variationData) {
            if (!empty($variationData['was_changed']) || !empty($variationData['configurable_attribute'])) {
                try {
                    $child = $this->productRepository->get($childSku);
                    $childIds[] = (int) $child->getId();
                } catch (\Exception $e) {
                    $this->logger->warning("Could not find variation SKU: $childSku");
                }
            }
        }

        // From existing associated products data
        foreach ($configurableProductsData as $childId => $childData) {
            if (!empty($childData['was_changed']) || is_numeric($childId)) {
                $childIds[] = (int) $childId;
            }
        }

        // From associated_product_ids (direct list)
        $associatedProductIds = (array) ($productData['associated_product_ids'] ?? []);
        foreach ($associatedProductIds as $id) {
            $childIds[] = (int) $id;
        }

        // From configurable-matrix (UI Component matrix)
        $configurableMatrixRaw = $productData['configurable-matrix'] ?? '[]';
        if (is_string($configurableMatrixRaw)) {
            $configurableMatrix = json_decode($configurableMatrixRaw, true) ?? [];
        } else {
            $configurableMatrix = (array) $configurableMatrixRaw;
        }

        foreach ($configurableMatrix as $matrixRow) {
            $childId = $matrixRow['id'] ?? null;
            $newProductFlag = !empty($matrixRow['newProduct']);
            
            // If ID is missing, non-numeric, or explicitly flagged as new
            if (!$childId || !is_numeric($childId) || $newProductFlag) {
                try {
                    $sku = $matrixRow['sku'] ?? '';
                    if (!$sku) {
                        continue;
                    }

                    try {
                        $child = $this->productRepository->get($sku);
                        $childId = (int) $child->getId();
                    } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                        // Create new simple product for this variation
                        $child = $this->productFactory->create();
                        $child->setTypeId(ProductType::TYPE_SIMPLE);
                        $child->setAttributeSetId($productData['new-variations-attribute-set-id'] ?? $product->getAttributeSetId());
                        $child->setStoreId(0);
                        $child->setWebsiteIds($product->getWebsiteIds());
                        $child->setSku($sku);
                        $child->setName($matrixRow['name'] ?? $sku);
                        $child->setStatus((int) ($matrixRow['status'] ?? 1));
                        $child->setVisibility(ProductVisibility::VISIBILITY_NOT_VISIBLE);
                        $child->setPrice((float) ($matrixRow['price'] ?? 0));
                        $child->setWeight((float) ($matrixRow['weight'] ?? 0));
                        $child->setTaxClassId($product->getTaxClassId());
                        
                        // Apply configurable attribute values
                        $configAttr = $matrixRow['configurable_attribute'] ?? '{}';
                        if (is_string($configAttr)) {
                            $configAttr = json_decode($configAttr, true) ?? [];
                        }
                        foreach ($configAttr as $code => $value) {
                            $child->setData($code, $value);
                        }

                        // Process variation-specific images.
                        // Magento's wizard sets role fields (image, small_image, etc.) but
                        // media_gallery.images may be empty — fall back to role fields.
                        [$childImageList, $childRoles] = $this->buildChildImageList($matrixRow);
                        if (!empty($childImageList)) {
                            $this->logger->info("Processing images for new variation " . $sku, [
                                'count' => count($childImageList), 'roles' => array_keys($childRoles),
                            ]);
                            $this->imageProcessor->process($child, [
                                'images'      => $childImageList,
                                'image_roles' => $childRoles,
                            ]);
                        }
                        // Do NOT copy parent images to this child.
                        // The parent's gallery may contain images uploaded for OTHER variations.

                        $child = $this->productRepository->save($child);
                        $childId = (int) $child->getId();
                    }
                } catch (\Exception $e) {
                    $this->logger->error("Failed to create new variation product: " . $e->getMessage(), [
                        'matrix_row' => $matrixRow
                    ]);
                    continue;
                }
            }

            if ($childId && is_numeric($childId)) {
                $childIds[] = (int) $childId;

                // Sync individual variation changes (status, price, etc.)
                try {
                    // Force store 0 (All Store Views) for loading and comparing variations
                    $child = $this->productRepository->getById((int) $childId, false, 0);
                    $attributesToSave = [];
                    $currentStatus = (int) $child->getStatus();
                    
                    if (isset($matrixRow['status'])) {
                        $newStatus = (int) $matrixRow['status'];
                        if ($currentStatus !== $newStatus) {
                            $child->setStatus($newStatus);
                            $child->setStoreId(0);
                            $this->productResource->saveAttribute($child, 'status');
                            $attributesToSave[] = 'status';
                        }
                    }

                    if (isset($matrixRow['price']) && is_numeric($matrixRow['price'])) {
                        $newPrice = (float) $matrixRow['price'];
                        if (abs((float)$child->getPrice() - $newPrice) > 0.0001) {
                            $child->setPrice($newPrice);
                            $child->setStoreId(0);
                            $this->productResource->saveAttribute($child, 'price');
                            $attributesToSave[] = 'price';
                        }
                    }

                    // Child Qty Update
                    if (isset($matrixRow['qty']) && is_numeric($matrixRow['qty'])) {
                        $newQty = (float) $matrixRow['qty'];
                        try {
                            $stockItem = $this->stockRegistry->getStockItemBySku($child->getSku());
                            if (abs((float)$stockItem->getQty() - $newQty) > 0.0001) {
                                $stockItem->setQty($newQty);
                                $stockItem->setIsInStock($newQty > 0);
                                $this->stockRegistry->updateStockItemBySku($child->getSku(), $stockItem);
                            }
                        } catch (\Exception $stockEx) {
                            $this->logger->warning("Failed to update qty for variation " . $child->getSku() . ": " . $stockEx->getMessage());
                        }
                    }
                    
                    // Process variation images if matrix has them
                    [$childImageList, $childRoles] = $this->buildChildImageList($matrixRow);
                    if (!empty($childImageList)) {
                        $this->logger->info("Processing images for existing variation $childId", [
                            'count' => count($childImageList), 'roles' => array_keys($childRoles),
                        ]);
                        $this->imageProcessor->process($child, [
                            'images'      => $childImageList,
                            'image_roles' => $childRoles,
                        ]);
                    }

                    if (!empty($matrixRow['was_changed'])) {
                        $this->logger->info("Variation $childId was flagged as changed in UI");
                    }

                } catch (\Exception $e) {
                    $this->logger->warning("Failed to update variation $childId: " . $e->getMessage());
                }
            }
        }

        $childIds = array_unique(array_filter($childIds));

        $this->logger->info("ConfigurableProcessor: processAssociatedProducts collected child IDs", [
            'parent_id' => $product->getId(),
            'child_ids' => $childIds,
        ]);

        return $childIds;
    }

    /**
     * Build the image list and role map for a variation from matrix row data.
     *
     * Magento's wizard sets role fields (image, small_image, thumbnail, swatch_image)
     * directly on the matrix row, but media_gallery.images is often empty.
     * We prefer media_gallery.images when available and fall back to role fields.
     *
     * @return array{0: array, 1: array}  [$imageList, $roleMap]
     */
    private function buildChildImageList(array $matrixRow): array
    {
        // Collect role → file path assignments
        $roleFiles = [];
        foreach (['image', 'small_image', 'thumbnail', 'swatch_image'] as $role) {
            $val = $matrixRow[$role] ?? null;
            if ($val && is_string($val) && $val !== 'no_selection') {
                $roleFiles[$role] = $val;
            }
        }

        // Collect images from media_gallery.images (may be array or numeric-keyed object)
        $mg = $matrixRow['media_gallery'] ?? [];
        $rawImages = $mg['images'] ?? [];
        $imageList = is_array($rawImages) ? array_values($rawImages) : [];

        // Fall back: if gallery images list is empty but role fields exist,
        // synthesise a single-image entry from the first role file.
        if (empty($imageList) && !empty($roleFiles)) {
            $imageList = [[
                'file'     => reset($roleFiles),
                'position' => 1,
                'disabled' => 0,
                'label'    => '',
            ]];
        }

        return [$imageList, $roleFiles];
    }
}
