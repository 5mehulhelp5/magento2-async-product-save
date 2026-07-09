<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Cron;

use Magento\Framework\App\ResourceConnection;
use Mohan\ProductQueueSave\Logger\Logger;

/**
 * Deletes completed queue_message / queue_message_status rows for our queue
 * and trims old rows from mohan_product_queue_log.
 *
 * queue_message.body is longtext and can be several hundred KB per product save.
 * Magento never auto-deletes completed messages, so this cron prevents unbounded growth.
 */
class CleanupCompletedMessages
{
    private const QUEUE_NAME  = 'mohan.product.queue.save';
    private const TOPIC_NAME  = 'mohan.product.queue.save';
    private const STATUS_COMPLETE = 4;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Logger $logger
    ) {}

    public function execute(): void
    {
        $connection = $this->resourceConnection->getConnection();

        try {
            // ── Step 1: Delete completed queue_message_status rows ────────────────
            // Only delete status=COMPLETE (4); leave NEW/IN_PROGRESS/RETRY/ERROR untouched.
            $queueTable  = $this->resourceConnection->getTableName('queue');
            $statusTable = $this->resourceConnection->getTableName('queue_message_status');
            $messageTable = $this->resourceConnection->getTableName('queue_message');

            $deletedStatus = $connection->delete(
                $statusTable,
                [
                    'queue_id IN (SELECT id FROM ' . $queueTable . ' WHERE name = ?)' => self::QUEUE_NAME,
                    'status = ?'   => self::STATUS_COMPLETE,
                ]
            );

            // ── Step 2: Delete orphaned queue_message rows ────────────────────────
            // A message row is orphaned when all its status entries have been removed.
            // Scope to our topic_name so we never touch other queues' messages.
            $deletedMessages = $connection->query(
                'DELETE qm FROM ' . $messageTable . ' qm
                 LEFT JOIN ' . $statusTable . ' qms ON qm.id = qms.message_id
                 WHERE qm.topic_name = ?
                 AND qms.id IS NULL',
                [self::TOPIC_NAME]
            )->rowCount();

            $this->logger->info('CleanupCompletedMessages: queue cleanup done', [
                'status_rows_deleted'  => $deletedStatus,
                'message_rows_deleted' => $deletedMessages,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('CleanupCompletedMessages: queue cleanup failed: ' . $e->getMessage());
        }

        // ── Step 3: Trim mohan_product_queue_log ──────────────────────────────
        // Keep successful entries for 30 days, failed/retry entries for 90 days.
        try {
            $logTable = $this->resourceConnection->getTableName('mohan_product_queue_log');

            $deletedSuccessLog = $connection->delete($logTable, [
                'status = ?'       => 'success',
                'created_at < ?' => new \Zend_Db_Expr('DATE_SUB(NOW(), INTERVAL 30 DAY)'),
            ]);

            $deletedFailedLog = $connection->delete($logTable, [
                'status IN (?)' => ['failed', 'retry'],
                'created_at < ?' => new \Zend_Db_Expr('DATE_SUB(NOW(), INTERVAL 90 DAY)'),
            ]);

            $this->logger->info('CleanupCompletedMessages: log table cleanup done', [
                'success_rows_deleted' => $deletedSuccessLog,
                'failed_rows_deleted'  => $deletedFailedLog,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('CleanupCompletedMessages: log table cleanup failed: ' . $e->getMessage());
        }
    }
}
