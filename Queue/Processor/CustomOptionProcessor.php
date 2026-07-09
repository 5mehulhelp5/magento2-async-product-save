<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Queue\Processor;

use Magento\Catalog\Model\Product\OptionFactory;
use Magento\Catalog\Model\Product\Option\ValueFactory as OptionValueFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Mohan\ProductQueueSave\Logger\Logger;

/**
 * Class CustomOptionProcessor
 */
class CustomOptionProcessor
{
    public function __construct(
        private readonly OptionFactory $optionFactory,
        private readonly OptionValueFactory $optionValueFactory,
        private readonly Logger $logger
    ) {}

    /**
     * @param ProductInterface|Product $product
     * @param array $options
     */
    public function process(ProductInterface $product, array $options): void
    {
        if (empty($options)) {
            return;
        }

        try {
            $optionObjects = [];

            foreach ($options as $optionData) {
                if (!empty($optionData['is_delete'])) {
                    continue;
                }

                $option = $this->optionFactory->create();
                $option->setData($optionData);
                $option->setProductSku($product->getSku());

                // Handle option values (for select-type options)
                if (!empty($optionData['values']) && is_array($optionData['values'])) {
                    $valueObjects = [];
                    foreach ($optionData['values'] as $valueData) {
                        if (!empty($valueData['is_delete'])) {
                            continue;
                        }
                        $value = $this->optionValueFactory->create();
                        $value->setData($valueData);
                        $valueObjects[] = $value;
                    }
                    $option->setValues($valueObjects);
                }

                $optionObjects[] = $option;
            }

            if (!empty($optionObjects)) {
                $product->setOptions($optionObjects);
                $product->setCanSaveCustomOptions(true);
            }
        } catch (\Exception $e) {
            $this->logger->error('CustomOptionProcessor error: ' . $e->getMessage(), [
                'product_id' => $product->getId(),
            ]);
        }
    }
}
