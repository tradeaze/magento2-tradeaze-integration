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
use Tradeaze\ApiIntegration\Service\Tradeaze;

class ReTryFailedTradeazeOrders
{

    /** @var CreateDeliveryInterface */
    protected CreateDeliveryInterface $createDelivery;

    /**
     * @param DeliveryStrategyResolver $deliveryStrategyResolver
     * @param Config $tradeazeConfig
     * @param CollectionFactory $orderCollectionFactory
     * @param OrderRepository $orderRepository
     * @param LoggerInterface $logger
     * @param SourceRepositoryInterface $sourceRepository
     */
    public function __construct(
        protected readonly DeliveryStrategyResolver $deliveryStrategyResolver,
        protected readonly Config $tradeazeConfig,
        protected readonly CollectionFactory $orderCollectionFactory,
        protected readonly OrderRepository $orderRepository,
        protected readonly LoggerInterface $logger,
        protected readonly SourceRepositoryInterface $sourceRepository
    ) {
        $this->createDelivery = $this->deliveryStrategyResolver->resolve();
    }

    /**
     * Retries failed Tradeaze delivery creation (up to 4 attempts before marking FAILED)
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
            ->addFieldToFilter(
                'tradeaze_order_status',
                ['like' => Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY . '%']
            )
            // Never re-send an order that already has a delivery
            ->addFieldToFilter('tradeaze_order_id', ['null' => true])
            // Only orders whose payment has landed. Orders still in pending_payment or
            // payment_review are parked here until their payment webhook promotes them,
            // so they cannot burn their retry attempts while waiting.
            ->addFieldToFilter(
                'state',
                ['in' => [Order::STATE_NEW, Order::STATE_PROCESSING]]
            )
            ->setPageSize(20);

        /** @var Order $order */
        foreach ($collection->getItems() as $order) {
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
                $currentStatus = $order->getTradeazeOrderStatus();
                $attemptNo = (int) str_replace(
                    Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY,
                    '',
                    $currentStatus
                );
                $attemptNo++;
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
}
