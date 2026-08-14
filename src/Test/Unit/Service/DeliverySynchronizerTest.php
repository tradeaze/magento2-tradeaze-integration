<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Service;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tradeaze\ApiIntegration\Api\TradeazeEndpoints\Delivery\CreateDeliveryInterface;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery\DeliveryStrategyResolver;
use Tradeaze\ApiIntegration\Service\DeliverySynchronizer;
use Tradeaze\ApiIntegration\Service\Tradeaze;
use TypeError;

class DeliverySynchronizerTest extends TestCase
{
    private CreateDeliveryInterface&MockObject $createDelivery;
    private OrderRepository&MockObject $orderRepository;
    private LoggerInterface&MockObject $logger;
    private SourceRepositoryInterface&MockObject $sourceRepository;
    private DeliverySynchronizer $synchronizer;

    protected function setUp(): void
    {
        $resolver = $this->createMock(DeliveryStrategyResolver::class);
        $this->createDelivery = $this->createMock(CreateDeliveryInterface::class);
        $resolver->method('resolve')->willReturn($this->createDelivery);
        $this->orderRepository = $this->getMockBuilder(OrderRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->sourceRepository = $this->createMock(SourceRepositoryInterface::class);

        $this->synchronizer = new DeliverySynchronizer(
            $resolver,
            $this->orderRepository,
            $this->logger,
            $this->sourceRepository,
        );
    }

    public function testProcessingOrderIsCreatedImmediately(): void
    {
        $order = $this->createOrder(Tradeaze::AWAITING_PROCESSING_STATUS);
        $this->createDelivery->expects($this->once())
            ->method('execute')
            ->with(['request' => $order])
            ->willReturn(['id' => 'trz-123']);

        $order->expects($this->once())->method('setTradeazeOrderId')->with('trz-123');
        $order->expects($this->once())->method('setTradeazeOrderStatus')->with(Tradeaze::PENDING_STATUS);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->synchronizer->synchronize($order, 'processing_transition');
    }

    public function testInvalidResponseEntersFirstFailedSyncState(): void
    {
        $order = $this->createOrder(Tradeaze::AWAITING_PROCESSING_STATUS);
        $this->createDelivery->method('execute')->willReturn([]);

        $order->expects($this->never())->method('setTradeazeOrderId');
        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with(Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY . '1');
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->synchronizer->synchronize($order, 'processing_transition');
    }

    public function testPhpErrorEntersFirstFailedSyncState(): void
    {
        $order = $this->createOrder(Tradeaze::AWAITING_PROCESSING_STATUS);
        $this->createDelivery->method('execute')
            ->willThrowException(new TypeError('Malformed delivery data'));

        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with(Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY . '1');
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->synchronizer->synchronize($order, 'processing_transition');
    }

    public function testCronFailureIncrementsExistingFailedSyncState(): void
    {
        $order = $this->createOrder(Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY . '1');
        $this->createDelivery->method('execute')
            ->willThrowException(new TypeError('API timeout'));

        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with(Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY . '2');

        $this->synchronizer->synchronize($order, 'cron');
    }

    public function testFailureAfterFourthRetryBecomesTerminal(): void
    {
        $order = $this->createOrder(Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY . '4');
        $this->createDelivery->method('execute')
            ->willThrowException(new TypeError('API timeout'));

        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with(Tradeaze::FAILED_STATUS);

        $this->synchronizer->synchronize($order, 'cron');
    }

    public function testStoredSourceIsUsedForCreation(): void
    {
        $order = $this->createOrder(Tradeaze::AWAITING_PROCESSING_STATUS, 'warehouse_a');
        $source = $this->createMock(SourceInterface::class);
        $this->sourceRepository->expects($this->once())
            ->method('get')
            ->with('warehouse_a')
            ->willReturn($source);
        $this->createDelivery->expects($this->once())
            ->method('execute')
            ->with([
                'request' => $order,
                'resolved_source' => $source,
            ])
            ->willReturn(['id' => 'trz-123']);

        $this->synchronizer->synchronize($order, 'processing_transition');
    }

    public function testMissingStoredSourceFallsBackToSourceResolution(): void
    {
        $order = $this->createOrder(Tradeaze::AWAITING_PROCESSING_STATUS, 'deleted_warehouse');
        $this->sourceRepository->method('get')
            ->willThrowException(new NoSuchEntityException(__('Source not found')));
        $this->createDelivery->expects($this->once())
            ->method('execute')
            ->with(['request' => $order])
            ->willReturn(['id' => 'trz-123']);
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Stored Tradeaze inventory source was not found; source will be re-resolved',
                $this->callback(function (array $context): bool {
                    return $context['order_id'] === '100000123'
                        && $context['source_code'] === 'deleted_warehouse';
                })
            );

        $this->synchronizer->synchronize($order, 'cron');
    }

    public function testRemoteDeliveryStatusIsNotCreatedAgain(): void
    {
        $order = $this->createOrder(Tradeaze::PENDING_STATUS);

        $this->createDelivery->expects($this->never())->method('execute');
        $this->orderRepository->expects($this->never())->method('save');

        $this->synchronizer->synchronize($order, 'processing_transition');
    }

    public function testExistingDeliveryIsNotCreatedAgain(): void
    {
        $order = $this->createOrder(
            Tradeaze::AWAITING_PROCESSING_STATUS,
            null,
            Order::STATE_PROCESSING,
            'trz-existing'
        );

        $this->createDelivery->expects($this->never())->method('execute');
        $this->orderRepository->expects($this->never())->method('save');

        $this->synchronizer->synchronize($order, 'processing_transition');
    }

    public function testNonProcessingOrderIsNotCreated(): void
    {
        $order = $this->createOrder(
            Tradeaze::AWAITING_PROCESSING_STATUS,
            null,
            Order::STATE_PENDING_PAYMENT
        );

        $this->createDelivery->expects($this->never())->method('execute');
        $this->orderRepository->expects($this->never())->method('save');

        $this->synchronizer->synchronize($order, 'cron');
    }

    private function createOrder(
        ?string $tradeazeStatus,
        ?string $sourceCode = null,
        string $state = Order::STATE_PROCESSING,
        ?string $tradeazeOrderId = null
    ): Order&MockObject {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->addMethods([
                'getTradeazeOrderId',
                'getTradeazeOrderStatus',
                'setTradeazeOrderId',
                'setTradeazeOrderStatus',
            ])
            ->onlyMethods([
                'addCommentToStatusHistory',
                'getData',
                'getEntityId',
                'getIncrementId',
                'getShippingMethod',
                'getState',
                'getStatus',
                'getStoreId',
            ])
            ->getMock();
        $order->method('getTradeazeOrderId')->willReturn($tradeazeOrderId);
        $order->method('getTradeazeOrderStatus')->willReturn($tradeazeStatus);
        $order->method('getData')->willReturnCallback(
            fn(string $key) => $key === 'tradeaze_source_code' ? $sourceCode : null
        );
        $order->method('getEntityId')->willReturn(123);
        $order->method('getIncrementId')->willReturn('100000123');
        $order->method('getShippingMethod')->willReturn('tradeaze_VAN_TODAY1400');
        $order->method('getState')->willReturn($state);
        $order->method('getStatus')->willReturn('payment_authorised');
        $order->method('getStoreId')->willReturn(1);

        return $order;
    }
}
