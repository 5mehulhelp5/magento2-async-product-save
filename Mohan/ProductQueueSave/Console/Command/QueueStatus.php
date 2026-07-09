<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Mohan\ProductQueueSave\Api\Data\QueueLogInterface;
use Mohan\ProductQueueSave\Logger\Logger;
use Mohan\ProductQueueSave\Model\ResourceModel\Queue\Log\CollectionFactory as LogCollectionFactory;

/**
 * Class QueueStatus
 *
 * CLI: bin/magento mohan:product-queue:status
 */
class QueueStatus extends Command
{
    public function __construct(
        private readonly LogCollectionFactory $logCollectionFactory,
        private readonly State $appState,
        private readonly Logger $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mohan:product-queue:status')
             ->setDescription('Display the current status of the Product Save Queue')
             ->addOption(
                 'status',
                 's',
                 InputOption::VALUE_OPTIONAL,
                 'Filter by status (pending|processing|success|failed|retry)',
                 null
             )
             ->addOption(
                 'limit',
                 'l',
                 InputOption::VALUE_OPTIONAL,
                 'Maximum rows to display',
                 20
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Already set
        }

        $statusFilter = $input->getOption('status');
        $limit        = (int) $input->getOption('limit');

        $collection = $this->logCollectionFactory->create();
        $collection->setOrder(QueueLogInterface::FIELD_CREATED_AT, 'DESC')
                   ->setPageSize($limit)
                   ->setCurPage(1);

        if ($statusFilter) {
            $collection->addFieldToFilter(QueueLogInterface::FIELD_STATUS, $statusFilter);
        }

        // Print summary
        $output->writeln('<info>Product Save Queue Log</info>');
        $output->writeln(str_repeat('-', 120));
        $output->writeln(sprintf(
            '%-6s %-36s %-10s %-8s %-12s %-6s %-6s %-19s %-19s',
            'ID', 'Message ID', 'Product ID', 'Store', 'Status', 'Retry', 'Admin', 'Created At', 'Processed At'
        ));
        $output->writeln(str_repeat('-', 120));

        foreach ($collection as $log) {
            $statusColor = match ($log->getStatus()) {
                QueueLogInterface::STATUS_SUCCESS    => 'info',
                QueueLogInterface::STATUS_FAILED     => 'error',
                QueueLogInterface::STATUS_PROCESSING => 'comment',
                QueueLogInterface::STATUS_RETRY      => 'comment',
                default                             => 'info',
            };

            $output->writeln(sprintf(
                '%-6s %-36s %-10s %-8s <%s>%-12s</%s> %-6s %-6s %-19s %-19s',
                $log->getLogId(),
                $log->getMessageId(),
                $log->getProductId() ?? 'NEW',
                $log->getStoreId(),
                $statusColor,
                strtoupper($log->getStatus()),
                $statusColor,
                $log->getRetryCount(),
                $log->getAdminUserId() ?? '-',
                $log->getCreatedAt(),
                $log->getProcessedAt() ?? '-'
            ));

            if ($log->getErrorMessage() && $input->getOption('verbosity') > OutputInterface::VERBOSITY_NORMAL) {
                $output->writeln('  <error>Error: ' . $log->getErrorMessage() . '</error>');
            }
        }

        $output->writeln(str_repeat('-', 120));

        // Counts per status
        $allCollection = $this->logCollectionFactory->create();
        $statuses = [
            QueueLogInterface::STATUS_PENDING,
            QueueLogInterface::STATUS_PROCESSING,
            QueueLogInterface::STATUS_SUCCESS,
            QueueLogInterface::STATUS_FAILED,
            QueueLogInterface::STATUS_RETRY,
        ];

        $output->writeln('');
        $output->writeln('<info>Summary:</info>');
        foreach ($statuses as $status) {
            $count = $this->logCollectionFactory->create()
                ->addFieldToFilter(QueueLogInterface::FIELD_STATUS, $status)
                ->getSize();
            $output->writeln(sprintf('  %-12s : %d', ucfirst($status), $count));
        }

        return Command::SUCCESS;
    }
}
