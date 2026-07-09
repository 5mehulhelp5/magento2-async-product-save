<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Controller\Adminhtml\Queue;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Math\Random;
use Mohan\ProductQueueSave\Api\ProductQueueSaveInterface;
use Mohan\ProductQueueSave\Helper\Config;
use Mohan\ProductQueueSave\Helper\ProductDataCollector;
use Magento\Framework\DataObjectFactory;
use Mohan\ProductQueueSave\Model\Queue\LogFactory;
use Mohan\ProductQueueSave\Model\ResourceModel\Queue\Log as LogResource;

/**
 * Class Save
 *
 * Handles the "Save via Queue" AJAX POST.
 *
 * Implements CsrfAwareActionInterface so we control CSRF validation ourselves
 * (we validate form_key from the POST body rather than from the URL secret key).
 * This prevents Magento's default URL-secret-key check from redirecting the
 * AJAX request to admin/catalog/index/index with a 404.
 */
class Save extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const ADMIN_RESOURCE = 'Mohan_ProductQueueSave::queue_save';

    public function __construct(
        Context $context,
        private readonly ProductQueueSaveInterface $productQueueSave,
        private readonly DataObjectFactory $dataObjectFactory,
        private readonly ProductDataCollector $productDataCollector,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly Random $mathRandom,
        private readonly LogFactory $logFactory,
        private readonly LogResource $logResource
    ) {
        parent::__construct($context);
    }

    /**
     * We handle CSRF ourselves via form_key in POST body.
     * Return null to skip Magento's default URL-key validation entirely.
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // form_key is sent as a URL query parameter to avoid PHP max_input_vars truncation
        $postFormKey    = (string) $request->getParam('form_key', '');
        $sessionFormKey = (string) $this->_session->getFormKey();

        if (empty($postFormKey) || $postFormKey !== $sessionFormKey) {
            return false;
        }

        return true;
    }

    /**
     * Return a JSON error instead of throwing an exception on CSRF failure.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode(403);
        $result->setData([
            'success' => false,
            'message' => (string) __('Invalid form key. Please reload the page and try again.'),
        ]);

        return new InvalidRequestException($result);
    }

    /**
     * Execute queue save action.
     */
    public function execute(): \Magento\Framework\Controller\Result\Json
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->isEnabled()) {
            return $result->setData([
                'success' => false,
                'message' => (string) __('Product Queue Save module is disabled.'),
            ]);
        }

        if (!$this->getRequest()->isPost()) {
            return $result->setData([
                'success' => false,
                'message' => (string) __('Invalid request method.'),
            ]);
        }

        try {
            $productData = $this->productDataCollector->collectFromRequest();
            $productId   = !empty($productData['product_id']) ? (int) $productData['product_id'] : null;
            $storeId     = (int) $this->getRequest()->getParam('store', 0);
            $adminUser   = $this->_auth->getUser();

            /** @var \Magento\Framework\DataObject $message */
            $message = $this->dataObjectFactory->create();
            $message->setData([
                'message_id' => $this->mathRandom->getUniqueHash('pqs_'),
                'product_id' => $productId,
                'store_id' => $storeId,
                'product_data' => $productData,
                'admin_user_id' => $adminUser ? (int) $adminUser->getId() : null,
                'created_at' => date('Y-m-d H:i:s'),
                'retry_count' => 0
            ]);

            // Create log entry in mohan_product_queue_log
            /** @var \Mohan\ProductQueueSave\Model\Queue\Log $log */
            $log = $this->logFactory->create();
            $log->setMessageId($message->getMessageId());
            $log->setProductId($productId);
            $log->setStoreId($storeId);
            $log->setAdminUserId($adminUser ? (int) $adminUser->getId() : null);
            $log->setStatus(\Mohan\ProductQueueSave\Api\Data\QueueLogInterface::STATUS_PENDING);
            
            // Extract SKU from product data if available
            if (!empty($productData['product']['sku'])) {
                $log->setSku($productData['product']['sku']);
            }

            $this->logResource->save($log);

            $published = $this->productQueueSave->publish($message);

            if ($published) {
                // Success message for the next page load (after redirect)
                $this->messageManager->addSuccessMessage(
                    __('Product queued successfully. It will be processed shortly.')
                );

                return $result->setData([
                    'success'    => true,
                    'message_id' => $message->getMessageId(),
                    'message'    => (string) __('Product queued successfully. It will be processed shortly.'),
                ]);
            }

            return $result->setData([
                'success' => false,
                'message' => (string) __('Failed to queue the product. Please try again.'),
            ]);

        } catch (\Throwable $e) {
            $this->_objectManager->get(\Psr\Log\LoggerInterface::class)->critical(
                'Mohan ProductQueueSave error: ' . $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );

            return $result->setData([
                'success' => false,
                'message' => (string) __('An error occurred: %1', $e->getMessage()),
            ]);
        }
    }

    /**
     * ACL check.
     */
    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
