<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Block\Adminhtml;

use Magento\Catalog\Block\Adminhtml\Product\Edit\Button\Generic;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\UiComponent\Context;
use Mohan\ProductQueueSave\Helper\Config;

/**
 * Class SaveViaQueueButton
 *
 * Provides the "Save via Queue" button definition for the product form.
 */
class SaveViaQueueButton extends Generic
{
    public function __construct(
        Context $context,
        Registry $registry,
        private readonly Config $config
    ) {
        parent::__construct($context, $registry);
    }

    /**
     * @return array
     */
    public function getButtonData(): array
    {
        if (!$this->config->isEnabled()) {
            return [];
        }

        $product = $this->getProduct();

        return [
            'label'          => __('Save via queue & close'),
            'class'          => 'save save-via-queue',
            'data_attribute' => [
                'mage-init' => [
                    'Mohan_ProductQueueSave/js/save-via-queue' => [
                        'url'         => $this->getQueueSaveUrl(),
                        'redirectUrl' => $this->getRedirectUrl(),
                        'queueLogUrl' => $this->getQueueLogUrl(),
                        'productId'   => $product ? $product->getId() : null,
                    ],
                ],
            ],
            'on_click'       => '',
            'sort_order'     => 105,
            'class' => 'save save-via-queue primary',
        ];
    }

    /**
     * Build the queue save URL including current form parameters.
     */
    private function getQueueSaveUrl(): string
    {
        $product = $this->getProduct();
        $params  = ['_current' => true, '_secure' => true];

        if ($product && $product->getId()) {
            $params['id'] = $product->getId();
        }

        return $this->getUrl('mohanqueue/queue/save', $params);
    }

    /**
     * After successful queue, redirect back to product grid.
     */
    private function getRedirectUrl(): string
    {
        return $this->getUrl('catalog/product/index');
    }

    /**
     * Queue log admin page URL — generated dynamically so it works on any Magento install.
     */
    private function getQueueLogUrl(): string
    {
        return $this->getUrl('mohanqueue/queue/index');
    }

    /**
     * Get the current product from registry.
     */
    public function getProduct(): ?\Magento\Catalog\Model\Product
    {
        return $this->registry->registry('current_product');
    }
}
