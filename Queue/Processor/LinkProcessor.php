<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Queue\Processor;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Mohan\ProductQueueSave\Logger\Logger;

/**
 * Class LinkProcessor
 *
 * Processes related, up-sell, cross-sell, and grouped product links.
 */
class LinkProcessor
{
    private const LINK_TYPE_MAP = [
        'related'    => 'related',
        'upsell'     => 'upsell',
        'crosssell'  => 'crosssell',
        'associated' => 'associated', // grouped
    ];

    public function __construct(
        private readonly ProductLinkInterfaceFactory $productLinkFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Logger $logger
    ) {}

    /**
     * @param ProductInterface|Product $product
     * @param array $productData
     */
    public function process(ProductInterface $product, array $productData): void
    {
        // Only sync links when the JS confirmed DynamicRows were successfully resolved.
        // If links_managed is false (DynamicRows not mounted yet), skip setProductLinks()
        // entirely — preserving existing DB links rather than clearing them.
        if (empty($productData['links_managed'])) {
            return;
        }

        $links = $productData['links'] ?? [];


        $flatKeys = [
            'related_products'   => 'related',
            'upsell_products'    => 'upsell',
            'crosssell_products' => 'crosssell',
        ];

        foreach ($flatKeys as $flatKey => $linkType) {
            if (!empty($productData[$flatKey])) {
                $ids = explode('&', (string) $productData[$flatKey]);
                foreach ($ids as $id) {
                    parse_str($id, $parsed);
                    foreach ($parsed as $productId => $position) {
                        $links[$linkType][] = [
                            'id'       => (int) $productId,
                            'position' => (int) $position,
                        ];
                    }
                }
            }
        }

        try {
            $linkObjects = [];

            foreach ($links as $linkType => $linkedProducts) {
                if (!is_array($linkedProducts)) {
                    continue;
                }

                foreach ($linkedProducts as $linkedProduct) {
                    // DynamicRows soft-delete: skip items marked for deletion
                    if (!empty($linkedProduct['delete']) || !empty($linkedProduct['_delete'])) {
                        continue;
                    }
                    $linkedId = (int) ($linkedProduct['id'] ?? $linkedProduct['product_id'] ?? 0);
                    if (!$linkedId) {
                        continue;
                    }

                    try {
                        $linkedProductModel = $this->productRepository->getById($linkedId);
                    } catch (\Exception $e) {
                        $this->logger->warning("Linked product $linkedId not found, skipping.");
                        continue;
                    }

                    $link = $this->productLinkFactory->create();
                    $link->setSku($product->getSku())
                         ->setLinkType($linkType)
                         ->setLinkedProductSku($linkedProductModel->getSku())
                         ->setLinkedProductType($linkedProductModel->getTypeId())
                         ->setPosition((int) ($linkedProduct['position'] ?? 0));

                    $linkObjects[] = $link;
                }
            }

            // Always call setProductLinks — even with an empty array — so that
            // removing all related/upsell/crosssell items is correctly persisted.
            // Skipping this call when empty leaves existing DB links untouched.
            $product->setProductLinks($linkObjects);
        } catch (\Exception $e) {
            $this->logger->error('LinkProcessor error: ' . $e->getMessage(), [
                'product_id' => $product->getId(),
            ]);
        }
    }
}
