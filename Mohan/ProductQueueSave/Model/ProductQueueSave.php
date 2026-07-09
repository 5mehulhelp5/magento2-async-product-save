<?php
/**
 * Mohan_ProductQueueSave
 *
 * @category  Mohan
 * @package   Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Model;

use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\DataObject;
use Mohan\ProductQueueSave\Api\ProductQueueSaveInterface;
use Mohan\ProductQueueSave\Logger\Logger;

/**
 * Class ProductQueueSave
 *
 * Publishes product save requests to the message queue.
 */
class ProductQueueSave implements ProductQueueSaveInterface
{
    private const TOPIC_NAME = 'mohan.product.queue.save';

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly \Magento\Framework\Serialize\SerializerInterface $serializer,
        private readonly Logger $logger
    ) {}

    /**
     * @inheritDoc
     */
    public function publish(DataObject $message): bool
    {
        try {
            // Convert the message object to a serialisable array then to JSON
            $data = $message->getData();
            $json = $this->serializer->serialize($data);
            
            $this->publisher->publish(self::TOPIC_NAME, $json);
            $this->logger->info(
                'Product queued for save.',
                [
                    'message_id' => $message->getMessageId(),
                    'product_id' => $message->getProductId(),
                    'store_id'   => $message->getStoreId(),
                ]
            );
            return true;
        } catch (\Exception $e) {
            $this->logger->critical(
                'Failed to publish product save message.',
                [
                    'message_id' => $message->getMessageId(),
                    'error'      => $e->getMessage(),
                    'trace'      => $e->getTraceAsString(),
                ]
            );
            return false;
        }
    }
}
