<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Observer\Sales;

use Exception;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterfaceFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tradeaze\ApiIntegration\Api\TradeazeEndpoints\Delivery\CreateDeliveryInterface;
use Tradeaze\ApiIntegration\Helper\Config;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery\DeliveryStrategyResolver;
use Tradeaze\ApiIntegration\Observer\Sales\OrderPlaceAfter;
use Tradeaze\ApiIntegration\Service\InventorySourceValidator;
use Tradeaze\ApiIntegration\Service\OrderPaymentStatus;

class OrderPlaceAfterTest extends TestCase
{
    private OrderPlaceAfter $observer;
    private Config&MockObject $config;
    private OrderRepository&MockObject $orderRepository;
    private LoggerInterface&MockObject $logger;
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
        $this->createDelivery = $this->createMock(CreateDeliveryInterface::class);
        $deliveryStrategyResolver->method('resolve')->willReturn($this->createDelivery);

        $this->config = $this->createMock(Config::class);
        $this->orderRepository = $this->getMockBuilder(OrderRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->logger = $this->createMock(LoggerInterface::class);

        $orderPaymentStatus = $this->createMock(OrderPaymentStatus::class);
        $orderPaymentStatus->method('isPaidInFull')
            ->willReturnCallback(fn() => $this->paidInFull);

        $this->observer = new OrderPlaceAfter(
            $deliveryStrategyResolver,
            $this->config,
            $this->orderRepository,
            $this->logger,
            $this->createMock(InventorySourceValidator::class),
            $this->createMock(AddressInterfaceFactory::class),
            $orderPaymentStatus
        );
    }

    private function createOrderMock(?string $shippingMethod, string $state = Order::STATE_NEW): Order&MockObject
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->addMethods(['setTradeazeOrderId', 'setTradeazeOrderStatus'])
            ->onlyMethods([
                'getState',
                'getShippingMethod',
                'getAllItems',
                'getShippingAddress',
                'setData',
                'addCommentToStatusHistory',
            ])
            ->getMock();

        $order->method('getShippingMethod')->willReturn($shippingMethod);
        $order->method('getState')->willReturn($state);
        // Keeps source resolution a no-op - it bails out when there are no simple items
        $order->method('getAllItems')->willReturn([]);

        return $order;
    }

    private function createObserverEvent(Order $order): Observer
    {
        $observer = $this->getMockBuilder(Observer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEvent'])
            ->getMock();
        $observer->method('getEvent')->willReturn(new DataObject(['order' => $order]));

        return $observer;
    }

    public function testDoesNothingWhenModuleIsDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $order = $this->createOrderMock('tradeaze_CAR_TODAY1400');

        $this->createDelivery->expects($this->never())->method('execute');
        $order->expects($this->never())->method('setTradeazeOrderStatus');

        $this->observer->execute($this->createObserverEvent($order));
    }

    public function testDoesNotFlagNonTradeazeOrderAwaitingPayment(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $order = $this->createOrderMock('flatrate_flatrate', Order::STATE_PENDING_PAYMENT);

        $this->createDelivery->expects($this->never())->method('execute');
        $order->expects($this->never())->method('setTradeazeOrderStatus');
        $this->orderRepository->expects($this->never())->method('save');

        $this->observer->execute($this->createObserverEvent($order));
    }

    public function testHandlesOrderWithoutShippingMethod(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        // Virtual and downloadable orders carry a null shipping_method
        $order = $this->createOrderMock(null);

        $this->createDelivery->expects($this->never())->method('execute');
        $order->expects($this->never())->method('setTradeazeOrderStatus');

        $this->observer->execute($this->createObserverEvent($order));
    }

    public function testDefersPendingPaymentOrderToRetryCron(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->paidInFull = false;
        $order = $this->createOrderMock('tradeaze_CAR_TODAY1400', Order::STATE_PENDING_PAYMENT);

        // AWAITINGPAYMENT, not FAILEDSYNC0 - no delivery has been attempted, so this must stay
        // distinguishable in the sales grid from an order whose delivery call actually failed
        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with('AWAITINGPAYMENT');

        $order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with($this->callback(function ($comment) {
                return str_contains((string) $comment, 'deferred')
                    && str_contains((string) $comment, 'pending_payment');
            }));

        // The delivery is created later, by the cron, once the payment webhook lands
        $this->createDelivery->expects($this->never())->method('execute');
        // The order placement flow persists the flag, so the observer must not save
        $this->orderRepository->expects($this->never())->method('save');

        $this->observer->execute($this->createObserverEvent($order));
    }

    public function testDefersPaymentReviewOrderToRetryCron(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->paidInFull = false;
        $order = $this->createOrderMock('tradeaze_CAR_TODAY1400', Order::STATE_PAYMENT_REVIEW);

        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with('AWAITINGPAYMENT');

        $this->createDelivery->expects($this->never())->method('execute');
        $this->orderRepository->expects($this->never())->method('save');

        $this->observer->execute($this->createObserverEvent($order));
    }

    public function testDefersAuthorisedButUncapturedOrderDespiteProcessingState(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        // Buy-now-pay-later and authorize-only gateways reach "processing" with nothing
        // captured, which is exactly the case a state-based gate would wrongly let through
        $this->paidInFull = false;
        $order = $this->createOrderMock('tradeaze_CAR_TODAY1400', Order::STATE_PROCESSING);

        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with('AWAITINGPAYMENT');

        $this->createDelivery->expects($this->never())->method('execute');
        $this->orderRepository->expects($this->never())->method('save');

        $this->observer->execute($this->createObserverEvent($order));
    }

    public function testIgnoresOrderCancelledAtPlacement(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $order = $this->createOrderMock('tradeaze_CAR_TODAY1400', Order::STATE_CANCELED);

        $this->createDelivery->expects($this->never())->method('execute');
        $order->expects($this->never())->method('setTradeazeOrderStatus');
        $this->orderRepository->expects($this->never())->method('save');

        $this->observer->execute($this->createObserverEvent($order));
    }

    public function testCreatesDeliveryWhenCapturedAtPlacement(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        // A capture-on-order gateway has already run Invoice::pay() by the time Order::place()
        // dispatches this event, so the money is in and the delivery goes inline
        $order = $this->createOrderMock('tradeaze_CAR_TODAY1400', Order::STATE_PROCESSING);

        $this->createDelivery->expects($this->once())
            ->method('execute')
            ->willReturn(['id' => 'trz-123']);

        $order->expects($this->once())->method('setTradeazeOrderId')->with('trz-123');
        $order->expects($this->once())->method('setTradeazeOrderStatus')->with('PENDING');
        $this->orderRepository->expects($this->once())->method('save');

        $this->observer->execute($this->createObserverEvent($order));
    }

    public function testCreatesDeliveryForPaidOrderRegardlessOfState(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        // The gate is the money, not the state - a paid order still sitting in "new" (a
        // provider that picks its own placement state) must still be sent
        $order = $this->createOrderMock('tradeaze_CAR_TODAY1400', Order::STATE_NEW);

        $this->createDelivery->expects($this->once())
            ->method('execute')
            ->willReturn(['id' => 'trz-456']);

        $order->expects($this->once())->method('setTradeazeOrderId')->with('trz-456');
        $order->expects($this->once())->method('setTradeazeOrderStatus')->with('PENDING');
        $this->orderRepository->expects($this->once())->method('save');

        $this->observer->execute($this->createObserverEvent($order));
    }

    public function testFlagsFailedApiCallForRetry(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $order = $this->createOrderMock('tradeaze_CAR_TODAY1400', Order::STATE_PROCESSING);

        $this->createDelivery->method('execute')
            ->willThrowException(new Exception('API timeout'));

        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with('FAILEDSYNC1');

        $this->logger->expects($this->once())->method('error')->with('API timeout');
        $this->orderRepository->expects($this->once())->method('save');

        $this->observer->execute($this->createObserverEvent($order));
    }
}
