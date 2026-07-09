<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Mohan\ProductQueueSave\Api\Data\QueueLogInterface;

class QueueStatus implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => QueueLogInterface::STATUS_PENDING,    'label' => __('Pending')],
            ['value' => QueueLogInterface::STATUS_PROCESSING, 'label' => __('Processing')],
            ['value' => QueueLogInterface::STATUS_SUCCESS,    'label' => __('Success')],
            ['value' => QueueLogInterface::STATUS_FAILED,     'label' => __('Failed')],
            ['value' => QueueLogInterface::STATUS_RETRY,      'label' => __('Retry')],
        ];
    }
}
