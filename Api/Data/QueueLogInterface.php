<?php
/**
 * Mohan_ProductQueueSave
 *
 * @category  Mohan
 * @package   Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Api\Data;

/**
 * Interface QueueLogInterface
 */
interface QueueLogInterface
{
    public const TABLE_NAME    = 'mohan_product_queue_log';

    public const FIELD_ID          = 'log_id';
    public const FIELD_MESSAGE_ID  = 'message_id';
    public const FIELD_PRODUCT_ID  = 'product_id';
    public const FIELD_SKU         = 'sku';
    public const FIELD_STATUS      = 'status';
    public const FIELD_STORE_ID    = 'store_id';
    public const FIELD_ADMIN_USER  = 'admin_user_id';
    public const FIELD_ERROR_MSG   = 'error_message';
    public const FIELD_RETRY_COUNT = 'retry_count';
    public const FIELD_CREATED_AT  = 'created_at';
    public const FIELD_PROCESSED_AT = 'processed_at';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS    = 'success';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_RETRY      = 'retry';

    public function getLogId(): ?int;
    public function getMessageId(): string;
    public function setMessageId(string $messageId): self;
    public function getProductId(): ?int;
    public function setProductId(?int $productId): self;
    public function getSku(): ?string;
    public function setSku(?string $sku): self;
    public function getStatus(): string;
    public function setStatus(string $status): self;
    public function getStoreId(): int;
    public function setStoreId(int $storeId): self;
    public function getAdminUserId(): ?int;
    public function setAdminUserId(?int $adminUserId): self;
    public function getErrorMessage(): ?string;
    public function setErrorMessage(?string $errorMessage): self;
    public function getRetryCount(): int;
    public function setRetryCount(int $retryCount): self;
    public function getCreatedAt(): string;
    public function getProcessedAt(): ?string;
    public function setProcessedAt(?string $processedAt): self;
}
