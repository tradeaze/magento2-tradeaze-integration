<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Observer\Sales;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterfaceFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tradeaze\ApiIntegration\Helper\Config;
use Tradeaze\ApiIntegration\Observer\Sales\OrderPlaceAfter;
use Tradeaze\ApiIntegration\Service\DeliverySynchronizer;
use Tradeaze\ApiIntegration\Service\InventorySourceValidator;

class OrderPlaceAfterTest extends TestCase
{
    private Config&MockObject $config;
    private OrderRepository&MockObject $orderRepository;
    private LoggerInterface&MockObject $logger;
    private DeliverySynchronizer&MockObject $deliverySynchronizer;
    private OrderPlaceAfter $observer;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->orderRepository = $this->getMockBuilder(OrderRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->deliverySynchronizer = $this->createMock(DeliverySynchronizer::class);

        $this->observer = new OrderPlaceAfter(
            $this->config,
            $this->orderRepository,
            $this->logger,
            $this->createMock(InventorySourceValidator::class),
            $this->createMock(AddressInterfaceFactory::class),
            $this->deliverySynchronizer,
        );
    }

    public function testTradeazeOrderAwaitingPaymentIsPersistedForLaterProcessing(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->addMethods(['setTradeazeOrderStatus'])
            ->onlyMethods([
                'getAllItems',
                'getIncrementId',
                'getShippingMethod',
                'getState',
                'getStatus',
            ])
            ->getMock();
        $order->method('getIncrementId')->willReturn('100000123');
        $order->method('getShippingMethod')->willReturn('tradeaze_VAN_TODAY1400');
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->method('getStatus')->willReturn('pending_payment');
        $order->method('getAllItems')->willReturn([]);

        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with('AWAITING_PROCESSING');
        $this->deliverySynchronizer->expects($this->never())->method('synchronize');
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->observer->execute($this->createObserver($order));
    }

    public function testProcessingStateCreatesDeliveryWhenMagentoStatusIsCustom(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getAllItems',
                'getIncrementId',
                'getShippingMethod',
                'getState',
                'getStatus',
            ])
            ->getMock();
        $order->method('getIncrementId')->willReturn('100000124');
        $order->method('getShippingMethod')->willReturn('tradeaze_CAR_TODAY1400');
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $order->method('getStatus')->willReturn('payment_authorised');
        $order->method('getAllItems')->willReturn([]);

        $this->deliverySynchronizer->expects($this->once())
            ->method('synchronize')
            ->with($order, 'order_placement');
        $this->orderRepository->expects($this->never())->method('save');

        $this->observer->execute($this->createObserver($order));
    }

    private function createObserver(Order $order): Observer
    {
        $event = new DataObject(['order' => $order]);
        $observer = $this->getMockBuilder(Observer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEvent'])
            ->getMock();
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }
}
