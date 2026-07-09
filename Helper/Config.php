<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    private const XML_PATH_ENABLED        = 'mohanqueue/general/enabled';
    private const XML_PATH_RETRY          = 'mohanqueue/general/retry_attempts';
    private const XML_PATH_NOTIFY         = 'mohanqueue/general/notify_on_failure';
    private const XML_PATH_ADMIN_EMAIL    = 'mohanqueue/general/admin_email';

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getRetryAttempts(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_RETRY, ScopeInterface::SCOPE_STORE);
    }

    public function isNotifyOnFailure(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_NOTIFY, ScopeInterface::SCOPE_STORE);
    }

    public function getAdminEmail(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_ADMIN_EMAIL, ScopeInterface::SCOPE_STORE);
    }
}
