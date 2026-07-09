<?php
/**
 * Mohan_ProductQueueSave
 *
 * @category  Mohan
 * @package   Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Model\Queue;

use Magento\Framework\Model\AbstractModel;
use Mohan\ProductQueueSave\Api\Data\QueueLogInterface;
use Mohan\ProductQueueSave\Model\ResourceModel\Queue\Log as LogResource;

/**
 * Class Log
 */
class Log extends AbstractModel implements QueueLogInterface
{
    protected function _construct(): void
    {
        $this->_init(LogResource::class);
    }

    public function getLogId(): ?int
    {
        $value = $this->getData(self::FIELD_ID);
        return $value !== null ? (int) $value : null;
    }

    public function getMessageId(): string
    {
        return (string) $this->getData(self::FIELD_MESSAGE_ID);
    }

    public function setMessageId(string $messageId): self
    {
        return $this->setData(self::FIELD_MESSAGE_ID, $messageId);
    }

    public function getProductId(): ?int
    {
        $value = $this->getData(self::FIELD_PRODUCT_ID);
        return $value !== null ? (int) $value : null;
    }

    public function setProductId(?int $productId): self
    {
        return $this->setData(self::FIELD_PRODUCT_ID, $productId);
    }

    public function getSku(): ?string
    {
        return $this->getData(self::FIELD_SKU);
    }

    public function setSku(?string $sku): self
    {
        return $this->setData(self::FIELD_SKU, $sku);
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::FIELD_STATUS);
    }

    public function setStatus(string $status): self
    {
        return $this->setData(self::FIELD_STATUS, $status);
    }

    public function getStoreId(): int
    {
        return (int) $this->getData(self::FIELD_STORE_ID);
    }

    public function setStoreId(int $storeId): self
    {
        return $this->setData(self::FIELD_STORE_ID, $storeId);
    }

    public function getAdminUserId(): ?int
    {
        $value = $this->getData(self::FIELD_ADMIN_USER);
        return $value !== null ? (int) $value : null;
    }

    public function setAdminUserId(?int $adminUserId): self
    {
        return $this->setData(self::FIELD_ADMIN_USER, $adminUserId);
    }

    public function getErrorMessage(): ?string
    {
        return $this->getData(self::FIELD_ERROR_MSG);
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        return $this->setData(self::FIELD_ERROR_MSG, $errorMessage);
    }

    public function getRetryCount(): int
    {
        return (int) $this->getData(self::FIELD_RETRY_COUNT);
    }

    public function setRetryCount(int $retryCount): self
    {
        return $this->setData(self::FIELD_RETRY_COUNT, $retryCount);
    }

    public function getCreatedAt(): string
    {
        return (string) $this->getData(self::FIELD_CREATED_AT);
    }

    public function getProcessedAt(): ?string
    {
        return $this->getData(self::FIELD_PROCESSED_AT);
    }

    public function setProcessedAt(?string $processedAt): self
    {
        return $this->setData(self::FIELD_PROCESSED_AT, $processedAt);
    }
}
