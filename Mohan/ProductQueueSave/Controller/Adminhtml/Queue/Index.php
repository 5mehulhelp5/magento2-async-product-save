<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Controller\Adminhtml\Queue;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Class Index
 *
 * Renders the Queue Log admin grid at:
 * Admin > Catalog > Product Queue Log
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Mohan_ProductQueueSave::queue_manage';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): \Magento\Framework\View\Result\Page
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Mohan_ProductQueueSave::queue_manage');
        $page->getConfig()->getTitle()->prepend(__('Product Queue Save Log'));
        return $page;
    }
}
