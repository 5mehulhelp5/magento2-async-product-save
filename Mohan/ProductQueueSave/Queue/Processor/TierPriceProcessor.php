<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Queue\Processor;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductTierPriceInterfaceFactory;
use Magento\Catalog\Model\Product;
use Mohan\ProductQueueSave\Logger\Logger;

/**
 * Class TierPriceProcessor
 *
 * Rebuilds tier price objects from serialised queue data.
 */
class TierPriceProcessor
{
    public function __construct(
        private readonly ProductTierPriceInterfaceFactory $tierPriceFactory,
        private readonly Logger $logger
    ) {}

    /**
     * @param ProductInterface|Product $product
     * @param array $tierPrices  Raw tier price rows from POST / queue data.
     */
    public function process(ProductInterface $product, array $tierPrices): void
    {
        // We no longer return early if empty, to allow clearing tier prices via setTierPrices([])

        try {
            $priceObjects = [];

            foreach ($tierPrices as $row) {
                if (!empty($row['delete'])) {
                    continue;
                }

                $tierPrice = $this->tierPriceFactory->create();
                
                // Be more inclusive with keys: POST data keys vs internal data keys
                $groupId = (int) ($row['cust_group'] ?? $row['customer_group_id'] ?? 0);
                $tierPrice->setCustomerGroupId($groupId);
                
                $qty = (float) ($row['price_qty'] ?? $row['qty'] ?? 1);
                $tierPrice->setQty($qty);

                $priceType = $row['value_type'] ?? 'fixed';
                if ($priceType === 'percent') {
                   $percentage = (float) ($row['percentage_value'] ?? $row['price'] ?? $row['value'] ?? 0);
                   
                   // Ensure extension attributes are initialized safely
                   $extAttributes = $tierPrice->getExtensionAttributes() 
                       ?? $tierPrice->getExtensionAttributesFactory()->create();
                   
                   if (method_exists($extAttributes, 'setPercentageValue')) {
                       $extAttributes->setPercentageValue($percentage);
                       $tierPrice->setExtensionAttributes($extAttributes);
                   }
                   $tierPrice->setValue(0.0);
                } else {
                    $price = (float) ($row['price'] ?? $row['value'] ?? 0);
                    $tierPrice->setValue($price);
                }

                $tierPrice->setWebsiteId((int) ($row['website_id'] ?? 0));
                $priceObjects[] = $tierPrice;
            }

            $product->setTierPrices($priceObjects);
            
            // Also set as raw data key so backend models and observers pick it up
            $product->setData('tier_price', $tierPrices);
        } catch (\Exception $e) {
            $this->logger->error('TierPriceProcessor error: ' . $e->getMessage(), [
                'product_id' => $product->getId(),
            ]);
        }
    }
}
