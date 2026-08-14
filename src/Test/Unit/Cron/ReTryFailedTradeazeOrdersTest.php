<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Cron;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tradeaze\ApiIntegration\Cron\ReTryFailedTradeazeOrders;
use Tradeaze\ApiIntegration\Service\DeliverySynchronizer;
use Tradeaze\ApiIntegration\Service\Tradeaze;

class ReTryFailedTradeazeOrdersTest extends TestCase
{
    private CollectionFactory&MockObject $orderCollectionFactory;
    private OrderRepository&MockObject $orderRepository;
    private LoggerInterface&MockObject $logger;
    private DeliverySynchronizer&MockObject $deliverySynchronizer;
    private ReTryFailedTradeazeOrders $cron;

    protected function setUp(): void
    {
        $this->orderCollectionFactory = $this->createMock(CollectionFactory::class);
        $this->orderRepository = $this->getMockBuilder(OrderRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->deliverySynchronizer = $this->createMock(DeliverySynchronizer::class);
        $this->cron = new ReTryFailedTradeazeOrders(
            $this->orderCollectionFactory,
            $this->orderRepository,
            $this->logger,
            $this->deliverySynchronizer,
        );
    }

    public function testWorkerSelectsOnlyReadyRecoveryOrdersBeforeApplyingBatchLimit(): void
    {
        $collection = $this->createCollection([]);
        $filterCalls = [];
        $collection->expects($this->exactly(2))
            ->method('addFieldToFilter')
            ->willReturnCallback(function ($field, $condition) use (&$filterCalls, $collection) {
                $filterCalls[] = [$field, $condition];
                return $collection;
            });
        $collection->expects($this->once())->method('setPageSize')->with(20)->willReturnSelf();

        $this->orderCollectionFactory->method('create')->willReturn($collection);
        $this->cron->execute();

        $this->assertSame(
            [
                ['tradeaze_order_status', 'tradeaze_order_status'],
                [
                    ['like' => Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY . '%'],
                    ['eq' => Tradeaze::AWAITING_PROCESSING_STATUS],
                ],
            ],
            $filterCalls[0]
        );
        $this->assertSame(
            [
                'state',
                ['in' => [Order::STATE_PROCESSING, Order::STATE_CANCELED, Order::STATE_CLOSED]],
            ],
            $filterCalls[1]
        );
    }

    public function testProcessingAwaitingOrderUsesSharedSynchronizer(): void
    {
        $order = $this->createOrder(Tradeaze::AWAITING_PROCESSING_STATUS, Order::STATE_PROCESSING);
        $this->setupCollection([$order]);

        $this->deliverySynchronizer->expects($this->once())
            ->method('synchronize')
            ->with($order, 'cron');

        $this->cron->execute();
    }

    public function testFailedOrderUsesSharedSynchronizer(): void
    {
        $order = $this->createOrder('FAILEDSYNC2', Order::STATE_PROCESSING);
        $this->setupCollection([$order]);

        $this->deliverySynchronizer->expects($this->once())
            ->method('synchronize')
            ->with($order, 'cron');

        $this->cron->execute();
    }

    public function testCanceledAwaitingOrderBecomesNotRequired(): void
    {
        $order = $this->createOrder(Tradeaze::AWAITING_PROCESSING_STATUS, Order::STATE_CANCELED);
        $this->setupCollection([$order]);

        $this->deliverySynchronizer->expects($this->never())->method('synchronize');
        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with(Tradeaze::NOT_REQUIRED_STATUS);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->cron->execute();
    }

    public function testClosedFailedOrderBecomesNotRequired(): void
    {
        $order = $this->createOrder('FAILEDSYNC1', Order::STATE_CLOSED);
        $this->setupCollection([$order]);

        $this->deliverySynchronizer->expects($this->never())->method('synchronize');
        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with(Tradeaze::NOT_REQUIRED_STATUS);

        $this->cron->execute();
    }

    public function testEmptyCollectionDoesNothing(): void
    {
        $this->setupCollection([]);

        $this->deliverySynchronizer->expects($this->never())->method('synchronize');
        $this->orderRepository->expects($this->never())->method('save');

        $this->cron->execute();
    }

    private function createOrder(string $tradeazeStatus, string $state): Order&MockObject
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->addMethods(['getTradeazeOrderStatus', 'setTradeazeOrderStatus'])
            ->onlyMethods(['addCommentToStatusHistory', 'getIncrementId', 'getState'])
            ->getMock();
        $order->method('getTradeazeOrderStatus')->willReturn($tradeazeStatus);
        $order->method('getIncrementId')->willReturn('100000123');
        $order->method('getState')->willReturn($state);

        return $order;
    }

    private function setupCollection(array $orders): void
    {
        $this->orderCollectionFactory->method('create')->willReturn($this->createCollection($orders));
    }

    private function createCollection(array $orders): Collection&MockObject
    {
        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getItems')->willReturn($orders);

        return $collection;
    }
}
