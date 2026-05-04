<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Exception\LocalizedException;

class CreateWebhook extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Tradeaze_ApiIntegration::system/config/create_webhook.phtml';

    /**
     * Get Element HTML
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    /**
     * Get the Ajax request URL
     *
     * @return string
     */
    public function getAjaxUrl(): string
    {
        return $this->getUrl('tradeaze/system_config/createwebhook');
    }

    /**
     * Get the button HTML
     *
     * @return string
     * @throws LocalizedException
     */
    public function getButtonHtml(): string
    {
        $button = $this->getLayout()->createBlock(
            Button::class
        )->setData([
            'id'    => 'tradeaze_create_webhook_btn',
            'label' => __('Create Webhooks'),
        ]);

        return $button->toHtml();
    }
}
