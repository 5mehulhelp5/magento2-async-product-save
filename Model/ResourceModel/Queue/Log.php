<?php
/**
 * Mohan_ProductQueueSave
 *
 * @category  Mohan
 * @package   Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Model\ResourceModel\Queue;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Mohan\ProductQueueSave\Api\Data\QueueLogInterface;

/**
 * Class Log
 */
class Log extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init(QueueLogInterface::TABLE_NAME, QueueLogInterface::FIELD_ID);
    }
}
