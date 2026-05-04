<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Plugin\Sales\Block\Adminhtml\Order;

use Magento\Framework\AuthorizationInterface;
use Magento\Sales\Block\Adminhtml\Order\View as OrderView;

class Button
{
    /**
     * @param AuthorizationInterface $authorization
     */
    public function __construct(
        protected readonly \Magento\Framework\AuthorizationInterface $authorization,
    ) {
    }

    /**
     * Add Cancel on Tradeaze on Admin order details page
     *
     * @param OrderView $subject
     * @return void
     */
    public function beforeSetLayout(OrderView $subject): void
    {
        if (! $this->authorization->isAllowed('Tradeaze_ApiIntegration::orderupdate')) {
            return;
        }

        $order = $subject->getOrder();
        if ($order && $order->getTradeazeOrderId() && $order->getTradeazeOrderStatus() !== 'CANCELLED') {
            $subject->addButton(
                'cancel_on_tradeaze_button',
                [
                    'label' => __('Cancel on Tradeaze'),
                    'class' => __('primary'),
                    'id' => 'cancel-on-tradeaze-button',
                    'onclick' => 'setLocation(\'' . $subject->getUrl('tradeaze/cancel/index') . '\')'
                ]
            );
        }
    }
}
