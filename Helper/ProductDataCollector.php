<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Helper;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Mohan\ProductQueueSave\Logger\Logger as CustomLogger;

/**
 * Class ProductDataCollector
 *
 * Extracts the full product POST data from the admin request and
 * normalises it into a serialisable array ready for the queue.
 */
class ProductDataCollector extends AbstractHelper
{
    public function __construct(
        Context $context,
        private readonly RequestInterface $request,
        private readonly SerializerInterface $serializer,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CustomLogger $customLogger
    ) {
        parent::__construct($context);
    }

    /**
     * Build a complete, serialisable product data array from the current request.
     */
    public function collectFromRequest(): array
    {
        $product   = $this->request->getPost('product', []);
        
        // If the JS sent product data as a JSON string (common in AJAX), decode it.
        if (is_string($product) && !empty($product)) {
            try {
                $product = $this->serializer->unserialize($product);
            } catch (\Exception $e) {
                // If it's not valid JSON, we'll keep it as is or fallback to empty array
                $product = [];
            }
        }
        $stockData = $product['stock_data'] ?? [];
        $qtyStatus = $product['quantity_and_stock_status'] ?? [];
        
        // Merge both sources to ensure we don't lose 'qty' or 'is_in_stock'
        if (is_array($qtyStatus)) {
            $stockData = array_merge($stockData, $qtyStatus);
        }

        $storeId   = (int) $this->request->getParam('store', 0);
        $productId = (int) $this->request->getParam('id', 0);

        // Ensure type_id is captured for new products
        if (empty($product['type_id'])) {
            $typeId = $this->request->getParam('type');
            if (!$typeId) {
                // Heuristic: check for configurable data in original POST
                $matrix = $this->request->getPost('configurable-matrix');
                $attributes = $this->request->getPost('attributes');
                if ((!empty($matrix) && $matrix !== '[]') || !empty($attributes)) {
                    $typeId = Configurable::TYPE_CODE;
                }
            }
            if ($typeId) {
                $product['type_id'] = $typeId;
                // Add logging if we have access to logger (context has logger)
                $this->_logger->info("ProductDataCollector: Detected type_id: " . $typeId);
            }
        }

        $data = [
            'product'                    => $product,
            'product_id'                 => $productId ?: null,
            'store_id'                   => $storeId,
            // ---- Images / gallery ----
            'images'                     => $this->extractImages($product),
            // ---- Tier prices ----
            'tier_price'                 => $product['tier_price'] ?? [],
            // ---- Configurable associations ----
            'configurable_products_data' => $this->request->getPost('configurable_products_data', '{}'),
            'configurable_attributes_data' => $this->request->getPost('configurable_attributes_data', '{}'),
            'variations-matrix'          => $this->request->getPost('variations-matrix', '{}'),
            'configurable-matrix'        => $this->normaliseConfigurableMatrix(
                $this->request->getPost('configurable-matrix', '[]')
            ),
            'associated_product_ids'     => $this->request->getPost('associated_product_ids', []),
            'attributes'                 => $this->request->getPost('attributes', []),
            // ---- Grouped product links ----
            'links'                      => $this->request->getPost('links', []),
            // ---- Bundle options ----
            'bundle_options'                  => $this->request->getPost('bundle_options', []),
            'bundle_selections'               => $this->request->getPost('bundle_selections', []),
            'affect_bundle_product_selections' => (bool) $this->request->getPost('affect_bundle_product_selections', false),
            // ---- Downloadable ----
            'downloadable'               => $this->request->getPost('downloadable', []),
            // ---- Custom options ----
            // Magento encodes options inside $_POST['product']['options'], not at the top level.
            'options'                    => $product['options'] ?? [],
            // ---- Related/Up-sell/Cross-sell ----
            'links_managed'              => (bool) $this->request->getPost('links_managed', false),
            'related_products'           => $product['related_products'] ?? '',
            'upsell_products'            => $product['upsell_products'] ?? '',
            'crosssell_products'         => $product['crosssell_products'] ?? '',
            // ---- Category ids ----
            'category_ids'               => $product['category_ids'] ?? [],
            // ---- Website ids ----
            'website_ids'                => $product['website_ids'] ?? [],
            // ---- Stock data ----
            'stock_data'                 => $stockData,
            // ---- Affect configurable product attributes ----
            'affect_configurable_product_attributes' =>
                $this->request->getPost('affect_configurable_product_attributes', 0),
            'new-variations-attribute-set-id' =>
                $this->request->getPost('new-variations-attribute-set-id', ''),
            // ---- Gallery removals by value_id (comma-separated, sent in URL to bypass max_input_vars) ----
            'gallery_remove_ids'         => $this->request->getParam('gallery_remove_ids', ''),
        ];

        return $data;
    }

    /**
     * Extract media gallery entries from the product POST array.
     */
    private function extractImages(array $product): array
    {
        $mediaGallery = $product['media_gallery'] ?? [];
        $images       = $mediaGallery['images'] ?? [];

        // Collect special image roles
        $imageRoles = [];
        foreach (['image', 'small_image', 'thumbnail', 'swatch_image'] as $role) {
            if (isset($product[$role])) {
                $imageRoles[$role] = $product[$role];
            }
        }

        return [
            'images'      => $images,
            'image_roles' => $imageRoles,
        ];
    }

    /**
     * Normalise the configurable-matrix POST value to a JSON string.
     *
     * jQuery may serialise the deep-cloned rawData array using bracket notation
     * (configurable-matrix[0][image]=…), in which case PHP decodes it to a nested
     * array.  Accept both forms and always return a JSON-encoded string so the
     * queue message body is consistent regardless of how the JS sent the data.
     *
     * @param string|array $raw
     */
    private function normaliseConfigurableMatrix($raw): string
    {
        if (is_array($raw)) {
            return json_encode(array_values($raw)) ?: '[]';
        }
        return is_string($raw) ? $raw : '[]';
    }

    /**
     * Collect data from an existing ProductInterface for re-queuing / retry.
     */
    public function collectFromProduct(ProductInterface $product): array
    {
        /** @var Product $product */
        $data = $product->getData();

        // Tier prices
        $tierPrices = [];
        foreach ($product->getTierPrices() as $tp) {
            $tierPrices[] = $tp->getData();
        }

        // Media gallery
        $images = [];
        foreach ($product->getMediaGalleryImages() as $image) {
            $images[] = $image->getData();
        }

        // Custom options
        $options = [];
        foreach ($product->getOptions() ?? [] as $option) {
            $optionData   = $option->getData();
            $optionValues = [];
            foreach ($option->getValues() ?? [] as $value) {
                $optionValues[] = $value->getData();
            }
            $optionData['values'] = $optionValues;
            $options[]            = $optionData;
        }

        // Configurable children
        $configurableData = [];
        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            /** @var Configurable $typeInstance */
            $typeInstance = $product->getTypeInstance();
            foreach ($typeInstance->getUsedProducts($product) as $child) {
                $configurableData[] = [
                    'product_id' => $child->getId(),
                    'sku'        => $child->getSku(),
                    'data'       => $child->getData(),
                ];
            }
        }

        return [
            'product'                      => $data,
            'product_id'                   => (int) $product->getId(),
            'store_id'                     => (int) $product->getStoreId(),
            'tier_price'                   => $tierPrices,
            'images'                       => [
                'images' => $images,
                'image_roles' => [
                    'image' => $product->getImage(),
                    'small_image' => $product->getSmallImage(),
                    'thumbnail' => $product->getThumbnail(),
                    'swatch_image' => $product->getSwatchImage()
                ]
            ],
            'category_ids'                 => $product->getCategoryIds(),
            'website_ids'                  => $product->getWebsiteIds(),
            'stock_data'                   => $product->getExtensionAttributes()
                                                ?->getStockItem()
                                                ?->getData() ?? [],
            'options'                      => $options,
            'configurable_products_data'   => json_encode($configurableData),
            'configurable_attributes_data' => '{}',
            'variations-matrix'            => '{}',
            'links'                        => [],
            'bundle_options'               => [],
            'bundle_selections'            => [],
            'downloadable'                 => [],
        ];
    }
}
