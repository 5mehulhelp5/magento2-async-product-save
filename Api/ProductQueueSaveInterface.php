<?php
/**
 * Mohan_ProductQueueSave
 *
 * @category  Mohan
 * @package   Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Api;

/**
 * Interface ProductQueueSaveInterface
 */
interface ProductQueueSaveInterface
{
    /**
     * Publish product data to the message queue.
     *
     * @param \Magento\Framework\DataObject $message
     * @return bool
     */
    public function publish(\Magento\Framework\DataObject $message): bool;
}
