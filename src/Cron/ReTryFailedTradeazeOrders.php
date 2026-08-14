<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Cron;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Psr\Log\LoggerInterface;
use Tradeaze\ApiIntegration\Service\DeliverySynchronizer;
use Tradeaze\ApiIntegration\Service\Tradeaze;

class ReTryFailedTradeazeOrders
{
    /**
     * @param CollectionFactory $orderCollectionFactory
     * @param OrderRepository $orderRepository
     * @param LoggerInterface $logger
     * @param DeliverySynchronizer $deliverySynchronizer
     */
    public function __construct(
        private readonly CollectionFactory $orderCollectionFactory,
        private readonly OrderRepository $orderRepository,
        private readonly LoggerInterface $logger,
        private readonly DeliverySynchronizer $deliverySynchronizer,
    ) {
    }

    /**
     * Recover missed processing transitions and retry failed Tradeaze delivery creation
     *
     * @return void
     */
    public function execute(): void
    {
        $collection = $this->orderCollectionFactory->create()
            ->addAttributeToSelect('*')
            ->addFieldToFilter(
                ['tradeaze_order_status', 'tradeaze_order_status'],
                [
                    ['like' => Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY . '%'],
                    ['eq' => Tradeaze::AWAITING_PROCESSING_STATUS],
                ]
            )
            ->addFieldToFilter(
                'state',
                ['in' => [Order::STATE_PROCESSING, Order::STATE_CANCELED, Order::STATE_CLOSED]]
            )
            ->setOrder('created_at', 'ASC')
            ->setPageSize(20);

        /** @var Order $order */
        foreach ($collection->getItems() as $order) {
            $currentStatus = (string) $order->getTradeazeOrderStatus();
            if (in_array($order->getState(), [Order::STATE_CANCELED, Order::STATE_CLOSED], true)) {
                $order->setTradeazeOrderStatus(Tradeaze::NOT_REQUIRED_STATUS);
                $order->addCommentToStatusHistory(
                    __('Tradeaze delivery was not created because the Magento order was canceled or closed.')
                );
                $this->logger->info(
                    'Tradeaze delivery is no longer required',
                    [
                        'order_id' => $order->getIncrementId(),
                        'state' => $order->getState(),
                        'tradeaze_status' => $currentStatus,
                    ]
                );
                $this->orderRepository->save($order);
                continue;
            }

            $this->deliverySynchronizer->synchronize($order, 'cron');
        }
    }
}
