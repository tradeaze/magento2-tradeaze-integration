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
use Tradeaze\ApiIntegration\Service\OrderPaymentStatus;

class ReTryFailedTradeazeOrdersTest extends TestCase
{
    private ReTryFailedTradeazeOrders $cron;
    private CollectionFactory&MockObject $orderCollectionFactory;
    private OrderRepository&MockObject $orderRepository;
    private LoggerInterface&MockObject $logger;
    private SourceRepositoryInterface&MockObject $sourceRepository;
    private CreateDeliveryInterface&MockObject $createDelivery;

    /**
     * What OrderPaymentStatus::isPaidInFull() reports for this test
     *
     * Read through a callback rather than a fixed willReturn() so an individual test can flip
     * it without having to re-stub a mock that setUp() has already configured.
     */
    private bool $paidInFull = true;

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

        $orderPaymentStatus = $this->createMock(OrderPaymentStatus::class);
        $orderPaymentStatus->method('isPaidInFull')
            ->willReturnCallback(fn() => $this->paidInFull);

        $this->cron = new ReTryFailedTradeazeOrders(
            $deliveryStrategyResolver,
            $this->createMock(Config::class),
            $this->orderCollectionFactory,
            $this->orderRepository,
            $this->logger,
            $this->sourceRepository,
            $orderPaymentStatus
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

    /**
     * @param array $orders
     * @param array|null $filters Populated with every addFieldToFilter() call, keyed by field
     * @return Collection&MockObject
     */
    private function setupCollection(array $orders, ?array &$filters = null): Collection&MockObject
    {
        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addFieldToFilter')
            ->willReturnCallback(function ($field, $condition) use (&$filters, $collection) {
                if (is_array($filters)) {
                    $filters[$field] = $condition;
                }
                return $collection;
            });
        $collection->method('setPageSize')->willReturnSelf();
        // The query chain ends on setOrder(), so it has to hand the collection back
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getItems')->willReturn($orders);
        $this->orderCollectionFactory->method('create')->willReturn($collection);

        return $collection;
    }

    public function testCollectionOnlySelectsUnsentOrdersAwaitingPaymentOrRetry(): void
    {
        $filters = [];
        $this->setupCollection([], $filters);

        $this->cron->execute();

        // OR'd, so orders parked at placement and ones whose delivery call failed both qualify
        $this->assertSame(
            [
                ['eq' => 'AWAITINGPAYMENT'],
                ['like' => 'FAILEDSYNC%'],
            ],
            $filters['tradeaze_order_status']
        );
        $this->assertSame(['null' => true], $filters['tradeaze_order_id']);

        // Coarse pre-filter: unpaid states are excluded in SQL so they cannot fill the page,
        // and "complete" stays eligible for orders invoiced and shipped between runs
        $this->assertSame(['in' => ['processing', 'complete']], $filters['state']);
    }

    public function testCollectionTakesOldestFirstWithHeadroom(): void
    {
        $collection = $this->setupCollection([]);

        $collection->expects($this->once())
            ->method('setOrder')
            ->with('entity_id', 'ASC');

        // Candidates, not deliveries - unpaid authorisations sit in "processing" and are
        // discarded per order, so the page has to be larger than the expected work
        $collection->expects($this->once())
            ->method('setPageSize')
            ->with($this->greaterThan(20));

        $this->cron->execute();
    }

    public function testOrderNotPaidInFullIsSkipped(): void
    {
        $this->paidInFull = false;

        $order = $this->createOrderMock('AWAITINGPAYMENT');
        $this->setupCollection([$order]);

        // An authorised-but-uncaptured order reaches "processing", so it survives the SQL
        // filter - it must not be sent, and must not burn a retry attempt either
        $this->createDelivery->expects($this->never())->method('execute');
        $this->orderRepository->expects($this->never())->method('save');
        $order->expects($this->never())->method('setTradeazeOrderStatus');

        $this->cron->execute();
    }

    public function testFirstFailureFromAwaitingPaymentIsAttemptOne(): void
    {
        $order = $this->createOrderMock('AWAITINGPAYMENT');
        $this->setupCollection([$order]);

        $this->createDelivery->method('execute')
            ->willThrowException(new Exception('API timeout'));

        // AWAITINGPAYMENT is not a retry status, so no attempt has been made yet
        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with('FAILEDSYNC1');

        $order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with($this->callback(fn($comment) => str_contains((string) $comment, '#1')));

        $this->cron->execute();
    }

    public function testPaidOrderParkedAtPlacementIsSentWithoutHavingFailedFirst(): void
    {
        $order = $this->createOrderMock('AWAITINGPAYMENT');
        $this->setupCollection([$order]);

        $this->createDelivery->expects($this->once())
            ->method('execute')
            ->willReturn(['id' => 'trz-555']);

        $order->expects($this->once())
            ->method('setTradeazeOrderId')
            ->with('trz-555');

        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with('PENDING');

        $this->cron->execute();
    }

    public function testFailedRetryFromSync0IncrementsToSync1(): void
    {
        $order = $this->createOrderMock('FAILEDSYNC0');
        $this->setupCollection([$order]);

        $this->createDelivery->method('execute')
            ->willThrowException(new Exception('API timeout'));

        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with('FAILEDSYNC1');

        // A deferred order has had no API call yet, so this really is the first attempt
        $order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with($this->callback(function ($comment) {
                return str_contains((string) $comment, '#1');
            }));

        $this->cron->execute();
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
