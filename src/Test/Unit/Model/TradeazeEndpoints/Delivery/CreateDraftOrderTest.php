<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Model\TradeazeEndpoints\Delivery;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Tradeaze\ApiIntegration\Model\DeliverySelection;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\ClientAbstract;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery\CreateDraftOrder;
use Tradeaze\ApiIntegration\Service\Tradeaze;

class CreateDraftOrderTest extends TestCase
{
    public function testRequestUsesPersistedAbsoluteDeliverySelection(): void
    {
        $shippingAddress = $this->getMockBuilder(OrderAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getStreetLine',
                'getPostcode',
                'getCity',
                'getCountryId',
                'getName',
                'getTelephone',
                'getCompany',
            ])
            ->getMock();
        $shippingAddress->method('getCountryId')->willReturn('GB');

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getShippingAddress', 'getAllItems', 'getIncrementId'])
            ->getMock();
        $order->method('getIncrementId')->willReturn('1000001');
        $order->method('getShippingAddress')->willReturn($shippingAddress);
        $order->method('getAllItems')->willReturn([]);
        $order->method('getData')->willReturnCallback(static fn(string $field) => match ($field) {
            DeliverySelection::FIELD_DELIVERY_OPTION_ID => 'car-next-working-day',
            DeliverySelection::FIELD_DELIVERY_DATE => '2026-08-17',
            DeliverySelection::FIELD_WINDOW_START_UTC => '2026-08-17T08:30:00Z',
            default => null,
        });

        $tradeaze = $this->getMockBuilder(Tradeaze::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCountryName'])
            ->getMock();
        $tradeaze->method('getCountryName')->with('GB')->willReturn('United Kingdom');

        $reflection = new ReflectionClass(CreateDraftOrder::class);
        /** @var CreateDraftOrder $endpoint */
        $endpoint = $reflection->newInstanceWithoutConstructor();

        $params = new ReflectionProperty(ClientAbstract::class, 'params');
        $params->setValue($endpoint, ['request' => $order]);
        $tradeazeProperty = new ReflectionProperty(ClientAbstract::class, 'tradeaze');
        $tradeazeProperty->setValue($endpoint, $tradeaze);

        $request = $endpoint->buildRequest();

        $this->assertSame('car-next-working-day', $request['deliveryOptionId']);
        $this->assertSame('2026-08-17T08:30:00Z', $request['startTime']);
    }
}
