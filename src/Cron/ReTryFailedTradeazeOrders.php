<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Cron;

use Exception;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Psr\Log\LoggerInterface;
use Tradeaze\ApiIntegration\Api\TradeazeEndpoints\Delivery\CreateDeliveryInterface;
use Tradeaze\ApiIntegration\Helper\Config;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery\DeliveryStrategyResolver;
use Tradeaze\ApiIntegration\Service\OrderPaymentStatus;
use Tradeaze\ApiIntegration\Service\Tradeaze;

class ReTryFailedTradeazeOrders
{
    /**
     * How many candidate orders to examine per run
     *
     * Candidates, not deliveries: authorize-only and pay-later payments sit in "processing"
     * unpaid, so the page needs headroom or a run of them stalls the orders behind.
     */
    private const ORDERS_PER_RUN = 100;

    /** @var CreateDeliveryInterface */
    protected CreateDeliveryInterface $createDelivery;

    /**
     * @param DeliveryStrategyResolver $deliveryStrategyResolver
     * @param Config $tradeazeConfig
     * @param CollectionFactory $orderCollectionFactory
     * @param OrderRepository $orderRepository
     * @param LoggerInterface $logger
     * @param SourceRepositoryInterface $sourceRepository
     * @param OrderPaymentStatus $orderPaymentStatus
     */
    public function __construct(
        protected readonly DeliveryStrategyResolver $deliveryStrategyResolver,
        protected readonly Config $tradeazeConfig,
        protected readonly CollectionFactory $orderCollectionFactory,
        protected readonly OrderRepository $orderRepository,
        protected readonly LoggerInterface $logger,
        protected readonly SourceRepositoryInterface $sourceRepository,
        protected readonly OrderPaymentStatus $orderPaymentStatus
    ) {
        $this->createDelivery = $this->deliveryStrategyResolver->resolve();
    }

    /**
     * Creates deliveries for orders whose payment has landed since placement, and retries
     * failed delivery creation (up to 4 attempts before marking FAILED)
     *
     * @return void
     * @throws AlreadyExistsException
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function execute(): void
    {
        $collection = $this->orderCollectionFactory->create()
            ->addAttributeToSelect('*')
            // Orders parked awaiting payment, plus ones whose delivery call has failed before
            ->addFieldToFilter(
                'tradeaze_order_status',
                [
                    ['eq' => Tradeaze::AWAITING_PAYMENT_STATUS],
                    ['like' => Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY . '%'],
                ]
            )
            // Never re-send an order that already has a delivery
            ->addFieldToFilter('tradeaze_order_id', ['null' => true])
            // Coarse, index-backed narrowing (SALES_ORDER_STATE); the real paid-in-full test runs
            // per order below. Excluding unpaid states keeps abandoned checkouts, which stay parked
            // indefinitely, from filling the page ahead of orders that are ready. "complete" is
            // eligible on purpose - an order invoiced and shipped between runs still needs sending.
            ->addFieldToFilter(
                'state',
                ['in' => [Order::STATE_PROCESSING, Order::STATE_COMPLETE]]
            )
            ->setPageSize(self::ORDERS_PER_RUN)
            // Oldest first, so a spike cannot leave older orders permanently at the back
            ->setOrder('entity_id', 'ASC');

        /** @var Order $order */
        foreach ($collection->getItems() as $order) {
            // The real gate - "processing" only means a payment action ran, not that money
            // arrived (authorize-only, pay-later, part-invoiced). See OrderPaymentStatus.
            if (!$this->orderPaymentStatus->isPaidInFull($order)) {
                $this->logger->warning(
                    "Tradeaze cron: Order '{$order->getIncrementId()}' being skipped because order is not fully paid"
                );
                continue;
            }

            try {
                $params = [
                    'request' => $order,
                ];

                // Use the stored source code from the original order placement
                $sourceCode = $order->getData('tradeaze_source_code');
                if ($sourceCode) {
                    try {
                        $source = $this->sourceRepository->get($sourceCode);
                        $params['resolved_source'] = $source;
                    } catch (NoSuchEntityException $e) {
                        $this->logger->warning(
                            "Tradeaze cron: stored source '{$sourceCode}' not found, will re-resolve"
                        );
                    }
                }

                $response = $this->createDelivery->execute($params);
                $order->setTradeazeOrderId($response['id']);
                $order->setTradeazeOrderStatus('PENDING');
                $order->addCommentToStatusHistory(
                    __('Tradeaze delivery created successfully. Order Id %1', $response['id'])
                );
                $this->orderRepository->save($order);
            } catch (Exception $e) {
                $this->logger->error($e->getMessage());
                $attemptNo = $this->getAttemptNumber($order->getTradeazeOrderStatus()) + 1;
                $newStatus = $attemptNo <= Tradeaze::MAX_NUMBER_OF_REATTEMPTS
                    ? Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY . $attemptNo
                    : Tradeaze::FAILED_STATUS;
                $order->addCommentToStatusHistory(
                    __('Attempt #%1 failed. Tradeaze delivery error: %2', $attemptNo, $e->getMessage())
                );
                $order->setTradeazeOrderStatus($newStatus);
                $this->orderRepository->save($order);
            }
        }
    }

    /**
     * How many delivery attempts this order has already had
     *
     * Anything that is not a retry status - an order parked awaiting payment, for instance -
     * has had no attempt yet, so the next failure is attempt #1.
     *
     * @param string|null $status
     * @return int
     */
    private function getAttemptNumber(?string $status): int
    {
        if ($status === null || !str_starts_with($status, Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY)) {
            return 0;
        }

        return (int) substr($status, strlen(Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY));
    }
}
