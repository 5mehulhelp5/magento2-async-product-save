<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Queue\Processor;

use Magento\Bundle\Api\Data\LinkInterfaceFactory;
use Magento\Bundle\Api\Data\OptionInterfaceFactory;
use Magento\Bundle\Model\Product\Price;
use Magento\Bundle\Model\Product\Type;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Mohan\ProductQueueSave\Logger\Logger;

/**
 * Sets bundle product options and selections from queue payload onto the product
 * extension attributes so Magento's Bundle\Model\Product\SaveHandler persists them.
 */
class BundleProcessor
{
    public function __construct(
        private readonly OptionInterfaceFactory $optionFactory,
        private readonly LinkInterfaceFactory $linkFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly Logger $logger
    ) {}

    /**
     * @param ProductInterface|Product $product
     * @param array $productData
     */
    public function process(ProductInterface $product, array $productData): void
    {
        if ($product->getTypeId() !== Type::TYPE_CODE) {
            return;
        }

        $bundlePost = $productData['bundle_options'] ?? [];
        $rawOptions = $bundlePost['bundle_options'] ?? [];

        // affect_bundle_product_selections must be true for Magento to persist selections.
        // Default to true whenever bundle data is present in the payload.
        $affect = isset($productData['affect_bundle_product_selections'])
            ? (bool) $productData['affect_bundle_product_selections']
            : !empty($rawOptions);

        $product->setCanSaveBundleSelections($affect);

        if (empty($rawOptions)) {
            // No options in payload — clear so SaveHandler can remove deleted ones.
            $ext = $product->getExtensionAttributes();
            $ext->setBundleProductOptions([]);
            $product->setExtensionAttributes($ext);
            return;
        }

        // Pre-load all selection products in one query to avoid N+1 getById() calls.
        $allProductIds = [];
        foreach ($rawOptions as $optionData) {
            if (!empty($optionData['delete'])) {
                continue;
            }
            foreach ($optionData['bundle_selections'] ?? [] as $linkData) {
                if (empty($linkData['delete']) && !empty($linkData['product_id'])) {
                    $allProductIds[] = (int) $linkData['product_id'];
                }
            }
        }
        $productCache = $this->loadProductsByIds(array_unique($allProductIds));

        $options = [];
        foreach ($rawOptions as $key => $optionData) {
            if (!empty($optionData['delete'])) {
                continue;
            }

            $bundleSelections = $optionData['bundle_selections'] ?? [];
            unset($optionData['bundle_selections']);

            if (empty($bundleSelections)) {
                continue;
            }

            /** @var \Magento\Bundle\Api\Data\OptionInterface $option */
            $option = $this->optionFactory->create(['data' => $optionData]);
            $option->setSku($product->getSku());

            $links = [];
            foreach ($bundleSelections as $linkData) {
                if (!empty($linkData['delete'])) {
                    continue;
                }
                // Admin form uses selection_id; API model expects id.
                if (!empty($linkData['selection_id'])) {
                    $linkData['id'] = $linkData['selection_id'];
                }
                try {
                    $links[] = $this->buildLink($product, $linkData, $productCache);
                } catch (\Exception $e) {
                    $this->logger->warning('BundleProcessor: skipping selection — ' . $e->getMessage(), [
                        'product_id' => $product->getId(),
                        'link_data'  => $linkData,
                    ]);
                }
            }

            $option->setProductLinks($links);
            $options[] = $option;
        }

        $ext = $product->getExtensionAttributes();
        $ext->setBundleProductOptions($options);
        $product->setExtensionAttributes($ext);

        $this->logger->info('BundleProcessor: options prepared', [
            'product_id'   => $product->getId(),
            'option_count' => count($options),
        ]);
    }

    /**
     * @param int[] $ids
     * @return array<int, ProductInterface>
     */
    private function loadProductsByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', $ids, 'in')
            ->create();
        $items = $this->productRepository->getList($searchCriteria)->getItems();
        $byId  = [];
        foreach ($items as $item) {
            $byId[(int) $item->getId()] = $item;
        }
        return $byId;
    }

    /**
     * @param array<int, ProductInterface> $productCache
     */
    private function buildLink(
        ProductInterface $product,
        array $linkData,
        array $productCache
    ): \Magento\Bundle\Api\Data\LinkInterface {
        /** @var \Magento\Bundle\Api\Data\LinkInterface $link */
        $link = $this->linkFactory->create(['data' => $linkData]);

        if ((int) $product->getPriceType() !== Price::PRICE_TYPE_DYNAMIC) {
            if (array_key_exists('selection_price_value', $linkData)) {
                $link->setPrice((float) $linkData['selection_price_value']);
            }
            if (array_key_exists('selection_price_type', $linkData)) {
                $link->setPriceType((int) $linkData['selection_price_type']);
            }
        }

        $id = (int) $linkData['product_id'];
        $linkedProduct = $productCache[$id] ?? $this->productRepository->getById($id);
        $link->setSku($linkedProduct->getSku());
        $link->setQty((float) ($linkData['selection_qty'] ?? 1));

        if (array_key_exists('selection_can_change_qty', $linkData)) {
            $link->setCanChangeQuantity((int) $linkData['selection_can_change_qty']);
        }

        return $link;
    }
}
