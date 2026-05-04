<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Cron;

use Exception;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tradeaze\ApiIntegration\Api\TradeazeEndpoints\Delivery\CreateDeliveryInterface;
use Tradeaze\ApiIntegration\Cron\ReTryFailedTradeazeOrders;
use Tradeaze\ApiIntegration\Helper\Config;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery\DeliveryStrategyResolver;
use Tradeaze\ApiIntegration\Service\Tradeaze;

class ReTryFailedTradeazeOrdersTest extends TestCase
{
    private ReTryFailedTradeazeOrders $cron;
    private CollectionFactory&MockObject $orderCollectionFactory;
    private OrderRepository&MockObject $orderRepository;
    private LoggerInterface&MockObject $logger;
    private SourceRepositoryInterface&MockObject $sourceRepository;
    private CreateDeliveryInterface&MockObject $createDelivery;

    protected function setUp(): void
    {
        $deliveryStrategyResolver = $this->createMock(DeliveryStrategyResolver::class);
        $this->orderCollectionFactory = $this->createMock(CollectionFactory::class);
        $this->orderRepository = $this->getMockBuilder(OrderRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->sourceRepository = $this->createMock(SourceRepositoryInterface::class);

        $this->createDelivery = $this->createMock(CreateDeliveryInterface::class);
        $deliveryStrategyResolver->method('resolve')->willReturn($this->createDelivery);

        $this->cron = new ReTryFailedTradeazeOrders(
            $deliveryStrategyResolver,
            $this->createMock(Config::class),
            $this->orderCollectionFactory,
            $this->orderRepository,
            $this->logger,
            $this->sourceRepository
        );
    }

    private function createOrderMock(string $status, ?string $sourceCode = null): Order&MockObject
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->addMethods(['setTradeazeOrderId', 'setTradeazeOrderStatus', 'getTradeazeOrderStatus'])
            ->onlyMethods(['getData', 'addCommentToStatusHistory'])
            ->getMock();

        $order->method('getTradeazeOrderStatus')->willReturn($status);
        $order->method('getData')
            ->willReturnCallback(fn($key) => match ($key) {
                'tradeaze_source_code' => $sourceCode,
                default => null
            });

        return $order;
    }

    private function setupCollection(array $orders): void
    {
        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getItems')->willReturn($orders);
        $this->orderCollectionFactory->method('create')->willReturn($collection);
    }

    public function testSuccessfulRetrySetsPendingStatus(): void
    {
        $order = $this->createOrderMock('FAILEDSYNC1');
        $this->setupCollection([$order]);

        $this->createDelivery->method('execute')
            ->willReturn(['id' => 'trz-789']);

        $order->expects($this->once())
            ->method('setTradeazeOrderId')
            ->with('trz-789');

        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with('PENDING');

        $this->orderRepository->expects($this->once())
            ->method('save');

        $this->cron->execute();
    }

    public function testFailedRetryIncrementsStatus(): void
    {
        $order = $this->createOrderMock('FAILEDSYNC1');
        $this->setupCollection([$order]);

        $this->createDelivery->method('execute')
            ->willThrowException(new Exception('API timeout'));

        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with('FAILEDSYNC2');

        $this->logger->expects($this->once())
            ->method('error')
            ->with('API timeout');

        $this->cron->execute();
    }

    public function testFailedRetryFromSync3IncrementToSync4(): void
    {
        $order = $this->createOrderMock('FAILEDSYNC3');
        $this->setupCollection([$order]);

        $this->createDelivery->method('execute')
            ->willThrowException(new Exception('fail'));

        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with('FAILEDSYNC4');

        $this->cron->execute();
    }

    public function testFailedRetryFromSync4SetsFailedStatus(): void
    {
        $order = $this->createOrderMock('FAILEDSYNC4');
        $this->setupCollection([$order]);

        $this->createDelivery->method('execute')
            ->willThrowException(new Exception('fail'));

        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with('FAILED');

        $this->cron->execute();
    }

    public function testRetryUsesStoredSourceCode(): void
    {
        $order = $this->createOrderMock('FAILEDSYNC1', 'warehouse_a');
        $this->setupCollection([$order]);

        $source = $this->createMock(SourceInterface::class);
        $this->sourceRepository->method('get')
            ->with('warehouse_a')
            ->willReturn($source);

        $this->createDelivery->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($params) use ($source) {
                return isset($params['resolved_source']) && $params['resolved_source'] === $source;
            }))
            ->willReturn(['id' => 'trz-123']);

        $this->cron->execute();
    }

    public function testRetryLogsWarningWhenSourceNotFound(): void
    {
        $order = $this->createOrderMock('FAILEDSYNC1', 'deleted_warehouse');
        $this->setupCollection([$order]);

        $this->sourceRepository->method('get')
            ->willThrowException(new NoSuchEntityException(__('Source not found')));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('deleted_warehouse'));

        $this->createDelivery->method('execute')
            ->willReturn(['id' => 'trz-123']);

        $this->cron->execute();
    }

    public function testRetryWithNoSourceCodeSkipsSourceLoading(): void
    {
        $order = $this->createOrderMock('FAILEDSYNC1', null);
        $this->setupCollection([$order]);

        $this->sourceRepository->expects($this->never())->method('get');

        $this->createDelivery->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($params) {
                return !isset($params['resolved_source']);
            }))
            ->willReturn(['id' => 'trz-123']);

        $this->cron->execute();
    }

    public function testEmptyCollectionDoesNothing(): void
    {
        $this->setupCollection([]);

        $this->createDelivery->expects($this->never())->method('execute');
        $this->orderRepository->expects($this->never())->method('save');

        $this->cron->execute();
    }

    public function testCommentShowsCorrectAttemptNumber(): void
    {
        $order = $this->createOrderMock('FAILEDSYNC2');
        $this->setupCollection([$order]);

        $this->createDelivery->method('execute')
            ->willThrowException(new Exception('timeout'));

        $order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with($this->callback(function ($comment) {
                return str_contains((string) $comment, '#3');
            }));

        $this->cron->execute();
    }
}
