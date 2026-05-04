<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Model\TradeazeEndpoints\Webhook;

use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\OrderRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tradeaze\ApiIntegration\Api\Data\DataInterface;
use Tradeaze\ApiIntegration\Helper\Config;
use Tradeaze\ApiIntegration\Model\Webhooks\OrderConfirmedManagement;

class OrderManagementAbstractTest extends TestCase
{
    private OrderConfirmedManagement $handler;
    private OrderFactory&MockObject $orderFactory;
    private OrderRepository&MockObject $orderRepository;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->orderFactory = $this->createMock(OrderFactory::class);
        $this->orderRepository = $this->getMockBuilder(OrderRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new OrderConfirmedManagement(
            $this->createMock(Config::class),
            $this->orderFactory,
            $this->orderRepository,
            $this->logger
        );
    }

    private function createPayload(
        ?string $id = 'trz-123',
        ?string $externalId = '000000001',
        ?string $orderStatus = 'CONFIRMED'
    ): DataInterface&MockObject {
        $data = $this->createMock(DataInterface::class);
        $data->method('getId')->willReturn($id);
        $data->method('getExternalId')->willReturn($externalId);
        $data->method('getOrderStatus')->willReturn($orderStatus);
        return $data;
    }

    private function createOrderMock(?int $id = 1): Order&MockObject
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->addMethods(['setTradeazeOrderStatus'])
            ->onlyMethods(['getId', 'loadByIncrementId', 'addCommentToStatusHistory'])
            ->getMock();

        $order->method('getId')->willReturn($id);
        $order->method('loadByIncrementId')->willReturnSelf();

        return $order;
    }

    public function testSuccessfulWebhookUpdatesOrder(): void
    {
        $data = $this->createPayload();
        $order = $this->createOrderMock();

        $this->orderFactory->method('create')->willReturn($order);

        $order->expects($this->once())
            ->method('setTradeazeOrderStatus')
            ->with('CONFIRMED');

        $order->expects($this->once())
            ->method('addCommentToStatusHistory');

        $this->orderRepository->expects($this->once())
            ->method('save')
            ->with($order);

        $this->handler->postOrderConfirmed($data);
    }

    public function testWebhookThrowsWebapiExceptionWhenOrderNotFound(): void
    {
        $data = $this->createPayload(externalId: '999999999');

        $order = $this->createOrderMock(null);
        $this->orderFactory->method('create')->willReturn($order);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Tradeaze webhook error'));

        $this->expectException(WebapiException::class);
        $this->expectExceptionMessage('Webhook processing failed');

        $this->handler->postOrderConfirmed($data);
    }

    public function testWebhookThrowsWebapiExceptionWhenMissingId(): void
    {
        $data = $this->createPayload(id: null);

        $this->expectException(WebapiException::class);

        $this->handler->postOrderConfirmed($data);
    }

    public function testWebhookThrowsWebapiExceptionWhenMissingExternalId(): void
    {
        $data = $this->createPayload(externalId: null);

        $this->expectException(WebapiException::class);

        $this->handler->postOrderConfirmed($data);
    }

    public function testWebhookThrowsWebapiExceptionWhenMissingOrderStatus(): void
    {
        $data = $this->createPayload(orderStatus: null);

        $this->expectException(WebapiException::class);

        $this->handler->postOrderConfirmed($data);
    }

    public function testWebhookLogsErrorButDoesNotExposeDetails(): void
    {
        $data = $this->createPayload();

        $this->orderFactory->method('create')
            ->willThrowException(new \RuntimeException('Database connection failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Database connection failed'));

        try {
            $this->handler->postOrderConfirmed($data);
            $this->fail('Expected WebapiException');
        } catch (WebapiException $e) {
            $this->assertSame('Webhook processing failed', $e->getMessage());
            $this->assertStringNotContainsString('Database', $e->getMessage());
        }
    }
}
