<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Observer\Sales;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tradeaze\ApiIntegration\Observer\Sales\OrderSaveCommitAfter;
use Tradeaze\ApiIntegration\Service\DeliverySynchronizer;
use Tradeaze\ApiIntegration\Service\Tradeaze;

class OrderSaveCommitAfterTest extends TestCase
{
    private DeliverySynchronizer&MockObject $deliverySynchronizer;
    private OrderSaveCommitAfter $observer;

    protected function setUp(): void
    {
        $this->deliverySynchronizer = $this->createMock(DeliverySynchronizer::class);
        $this->observer = new OrderSaveCommitAfter($this->deliverySynchronizer);
    }

    public function testAwaitingTradeazeOrderIsSynchronizedWhenMagentoCommitsProcessingState(): void
    {
        $order = $this->createOrder(
            Order::STATE_PROCESSING,
            'AWAITING_PROCESSING',
            'tradeaze_VAN_TODAY1400',
            'payment_authorised'
        );

        $this->deliverySynchronizer->expects($this->once())
            ->method('synchronize')
            ->with($order, 'processing_transition');

        $this->observer->execute($this->createObserver($order));
    }

    public function testRepeatedProcessingSaveDoesNotSynchronizeAnOrderAgain(): void
    {
        $order = $this->createOrder(
            Order::STATE_PROCESSING,
            Tradeaze::PENDING_STATUS,
            'tradeaze_VAN_TODAY1400'
        );

        $this->deliverySynchronizer->expects($this->never())->method('synchronize');

        $this->observer->execute($this->createObserver($order));
    }

    private function createOrder(
        string $state,
        string $tradeazeStatus,
        string $shippingMethod,
        ?string $status = null
    ): Order&MockObject {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->addMethods(['getTradeazeOrderId', 'getTradeazeOrderStatus', 'setTradeazeOrderStatus'])
            ->onlyMethods([
                'getEntityId',
                'getIncrementId',
                'getShippingMethod',
                'getState',
                'getStatus',
                'getStoreId',
            ])
            ->getMock();
        $order->method('getEntityId')->willReturn(123);
        $order->method('getIncrementId')->willReturn('100000123');
        $order->method('getShippingMethod')->willReturn($shippingMethod);
        $order->method('getState')->willReturn($state);
        $order->method('getStatus')->willReturn($status ?? $state);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getTradeazeOrderStatus')->willReturn($tradeazeStatus);
        $order->method('getTradeazeOrderId')->willReturn(null);

        return $order;
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
