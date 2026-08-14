<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Service;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use Psr\Log\LoggerInterface;
use Throwable;
use Tradeaze\ApiIntegration\Api\TradeazeEndpoints\Delivery\CreateDeliveryInterface;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery\DeliveryStrategyResolver;
use UnexpectedValueException;

class DeliverySynchronizer
{
    /** @var CreateDeliveryInterface */
    private CreateDeliveryInterface $createDelivery;

    /**
     * @param DeliveryStrategyResolver $deliveryStrategyResolver
     * @param OrderRepository $orderRepository
     * @param LoggerInterface $logger
     * @param SourceRepositoryInterface $sourceRepository
     */
    public function __construct(
        DeliveryStrategyResolver $deliveryStrategyResolver,
        private readonly OrderRepository $orderRepository,
        private readonly LoggerInterface $logger,
        private readonly SourceRepositoryInterface $sourceRepository,
    ) {
        $this->createDelivery = $deliveryStrategyResolver->resolve();
    }

    /**
     * Create a Tradeaze delivery and persist the synchronization outcome
     *
     * @param Order $order
     * @param string $trigger
     * @return void
     */
    public function synchronize(Order $order, string $trigger): void
    {
        if ($order->getTradeazeOrderId()) {
            $this->logger->info(
                'Tradeaze delivery synchronization skipped because a delivery id already exists',
                $this->getLogContext($order, $trigger)
            );
            return;
        }

        if ($order->getState() !== Order::STATE_PROCESSING) {
            $this->logger->info(
                'Tradeaze delivery synchronization deferred because the Magento order is not processing',
                $this->getLogContext($order, $trigger)
            );
            return;
        }

        $currentStatus = (string) $order->getTradeazeOrderStatus();
        if ($currentStatus !== ''
            && $currentStatus !== Tradeaze::AWAITING_PROCESSING_STATUS
            && !str_starts_with($currentStatus, Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY)
        ) {
            $this->logger->info(
                'Tradeaze delivery synchronization skipped because the order is not eligible for creation',
                $this->getLogContext($order, $trigger)
            );
            return;
        }

        try {
            $this->logger->info(
                'Tradeaze delivery synchronization attempt started',
                $this->getLogContext($order, $trigger)
            );

            $params = ['request' => $order];
            $sourceCode = $order->getData('tradeaze_source_code');
            if ($sourceCode) {
                try {
                    $params['resolved_source'] = $this->sourceRepository->get($sourceCode);
                } catch (NoSuchEntityException) {
                    $this->logger->warning(
                        'Stored Tradeaze inventory source was not found; source will be re-resolved',
                        $this->getLogContext($order, $trigger) + ['source_code' => $sourceCode]
                    );
                }
            }

            $response = $this->createDelivery->execute($params);
            if (!isset($response['id']) || !is_string($response['id']) || trim($response['id']) === '') {
                throw new UnexpectedValueException(
                    'Tradeaze delivery response did not include a delivery id'
                );
            }

            $order->setTradeazeOrderId($response['id']);
            $order->setTradeazeOrderStatus(Tradeaze::PENDING_STATUS);
            $order->addCommentToStatusHistory(
                __('Tradeaze delivery created successfully. Order Id %1', $response['id'])
            );
            $this->logger->info(
                'Tradeaze delivery synchronization succeeded',
                $this->getLogContext($order, $trigger) + [
                    'tradeaze_status' => Tradeaze::PENDING_STATUS,
                    'tradeaze_delivery_id' => $response['id'],
                ]
            );
        } catch (Throwable $exception) {
            $attemptNo = $this->getNextAttemptNumber($currentStatus);
            $newStatus = $attemptNo <= Tradeaze::MAX_NUMBER_OF_REATTEMPTS
                ? Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY . $attemptNo
                : Tradeaze::FAILED_STATUS;

            $order->setTradeazeOrderStatus($newStatus);
            $order->addCommentToStatusHistory(
                __('Attempt #%1 failed. Tradeaze delivery error: %2', $attemptNo, $exception->getMessage())
            );
            $this->logger->error(
                $exception->getMessage(),
                $this->getLogContext($order, $trigger) + [
                    'previous_tradeaze_status' => $currentStatus,
                    'next_tradeaze_status' => $newStatus,
                    'attempt' => $attemptNo,
                    'exception' => $exception::class,
                ]
            );
        }

        $this->orderRepository->save($order);
    }

    /**
     * Calculate the next failed synchronization attempt number
     *
     * @param string $currentStatus
     * @return int
     */
    private function getNextAttemptNumber(string $currentStatus): int
    {
        $pattern = '/^' . preg_quote(Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY, '/') . '(\d+)$/';
        if (preg_match($pattern, $currentStatus, $matches) === 1) {
            return ((int) $matches[1]) + 1;
        }

        return 1;
    }

    /**
     * Build structured synchronization log context
     *
     * @param Order $order
     * @param string $trigger
     * @return array
     */
    private function getLogContext(Order $order, string $trigger): array
    {
        return [
            'order_id' => $order->getIncrementId(),
            'entity_id' => $order->getEntityId(),
            'state' => $order->getState(),
            'status' => $order->getStatus(),
            'shipping_method' => $order->getShippingMethod(),
            'store_id' => $order->getStoreId(),
            'tradeaze_status' => $order->getTradeazeOrderStatus(),
            'trigger' => $trigger,
        ];
    }
}
