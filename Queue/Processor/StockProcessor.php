<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Queue\Processor;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Mohan\ProductQueueSave\Logger\Logger;

/**
 * Class StockProcessor
 *
 * Applies stock/inventory data to the product before save.
 */
class StockProcessor
{
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StockItemInterfaceFactory $stockItemFactory,
        private readonly Logger $logger
    ) {}

    /**
     * @param ProductInterface $product
     * @param array $stockData
     */
    public function process(ProductInterface $product, array $stockData): void
    {
        if (empty($stockData)) {
            $this->logger->info("StockProcessor: No stock data to process for SKU: " . $product->getSku());
            return;
        }

        $this->logger->info("StockProcessor processing data for SKU: " . $product->getSku(), ['data' => $stockData]);

        try {
            if ($product->getId()) {
                $stockItem = $this->stockRegistry->getStockItemBySku(
                    $product->getSku() ?: '',
                    $product->getStore()->getWebsiteId()
                );
            } else {
                // New product, create a fresh stock item
                $stockItem = $this->stockItemFactory->create();
            }

            if (!$stockItem->getItemId() && $product->getId()) {
                $stockItem->setProductId((int) $product->getId());
            }

            // Map common stock fields
            $fieldMap = [
                'qty'                          => 'qty',
                'is_in_stock'                  => 'isInStock',
                'stock_status'                 => 'isInStock', // Handle 'stock_status' key from UI
                'manage_stock'                 => 'manageStock',
                'use_config_manage_stock'      => 'useConfigManageStock',
                'min_qty'                      => 'minQty',
                'use_config_min_qty'           => 'useConfigMinQty',
                'min_sale_qty'                 => 'minSaleQty',
                'use_config_min_sale_qty'      => 'useConfigMinSaleQty',
                'max_sale_qty'                 => 'maxSaleQty',
                'use_config_max_sale_qty'      => 'useConfigMaxSaleQty',
                'backorders'                   => 'backorders',
                'use_config_backorders'        => 'useConfigBackorders',
                'notify_stock_qty'             => 'notifyStockQty',
                'use_config_notify_stock_qty'  => 'useConfigNotifyStockQty',
                'enable_qty_increments'        => 'enableQtyIncrements',
                'use_config_enable_qty_inc'    => 'useConfigEnableQtyInc',
                'qty_increments'               => 'qtyIncrements',
                'use_config_qty_increments'    => 'useConfigQtyIncrements',
                'is_decimal_divided'           => 'isDecimalDivided',
            ];

            foreach ($fieldMap as $dataKey => $setter) {
                if (array_key_exists($dataKey, $stockData)) {
                    $method = 'set' . ucfirst($setter);
                    if (method_exists($stockItem, $method)) {
                        $stockItem->$method($stockData[$dataKey]);
                    }
                }
            }

            // Assign stock item to the product extension attributes
            $extensionAttributes = $product->getExtensionAttributes();
            $extensionAttributes->setStockItem($stockItem);
            $product->setExtensionAttributes($extensionAttributes);

            // Directly update the stock item in the registry for immediate persistence
            if ($product->getId()) {
                $this->stockRegistry->updateStockItemBySku($product->getSku(), $stockItem);
            }

            // Also set necessary data keys so backend models and observers pick it up
            $product->setData('stock_data', $stockData);
            $product->setData('quantity_and_stock_status', $stockData);

            // Ensure top-level fields like 'qty' are also set on the product model
            if (isset($stockData['qty'])) {
                $product->setQty((float)$stockData['qty']);
            }
            if (isset($stockData['is_in_stock'])) {
                $isInStock = (bool)$stockData['is_in_stock'];
                $product->setIsInStock($isInStock);
                $stockItem->setIsInStock($isInStock);
            } elseif (isset($stockData['stock_status'])) {
                $isInStock = (bool)$stockData['stock_status'];
                $product->setIsInStock($isInStock);
                $stockItem->setIsInStock($isInStock);
            }

            $this->logger->info("StockProcessor applied data", [
                'qty' => $stockItem->getQty(),
                'is_in_stock' => $stockItem->getIsInStock()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('StockProcessor error: ' . $e->getMessage(), [
                'product_id' => $product->getId(),
                'trace'      => $e->getTraceAsString()
            ]);
        }
    }
}
