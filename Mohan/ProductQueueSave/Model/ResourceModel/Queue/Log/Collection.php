<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Model\ResourceModel\Queue\Log;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Mohan\ProductQueueSave\Model\Queue\Log;
use Mohan\ProductQueueSave\Model\ResourceModel\Queue\Log as LogResource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'log_id';

    protected function _construct(): void
    {
        $this->_init(Log::class, LogResource::class);
    }
}
