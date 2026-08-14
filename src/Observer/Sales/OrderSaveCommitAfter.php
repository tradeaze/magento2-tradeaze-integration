<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Observer\Sales;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Tradeaze\ApiIntegration\Service\DeliverySynchronizer;
use Tradeaze\ApiIntegration\Service\Tradeaze;

class OrderSaveCommitAfter implements ObserverInterface
{
    /**
     * @param DeliverySynchronizer $deliverySynchronizer
     */
    public function __construct(
        private readonly DeliverySynchronizer $deliverySynchronizer,
    ) {
    }

    /**
     * Synchronize a deferred Tradeaze delivery once Magento commits the processing state
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /** @var Order $order */
        $order = $observer->getEvent()->getData('order');

        if (!str_starts_with((string) $order->getShippingMethod(), 'tradeaze_')
            || $order->getState() !== Order::STATE_PROCESSING
            || $order->getTradeazeOrderStatus() !== Tradeaze::AWAITING_PROCESSING_STATUS
            || $order->getTradeazeOrderId()
        ) {
            return;
        }

        $this->deliverySynchronizer->synchronize($order, 'processing_transition');
    }
}
