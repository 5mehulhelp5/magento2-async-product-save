<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Ui\DataProvider;

use Magento\Framework\Api\Filter;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Mohan\ProductQueueSave\Model\ResourceModel\Queue\Log\CollectionFactory;

/**
 * Class QueueLogDataProvider
 *
 * Supplies data to the mohan_product_queue_log_listing UI component.
 */
class QueueLogDataProvider extends AbstractDataProvider
{
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
    }

    public function getData(): array
    {
        if (!$this->getCollection()->isLoaded()) {
            $this->getCollection()->load();
        }

        $items = $this->getCollection()->toArray();

        return [
            'totalRecords' => $this->getCollection()->getSize(),
            'items'        => array_values($items['items'] ?? $items),
        ];
    }

    public function addFilter(Filter $filter): void
    {
        $this->getCollection()->addFieldToFilter(
            $filter->getField(),
            [$filter->getConditionType() => $filter->getValue()]
        );
    }
}
