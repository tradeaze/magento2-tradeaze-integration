<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Model\TradeazeEndpoints\Delivery;

use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterfaceFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Tradeaze\ApiIntegration\Helper\Config;
use Tradeaze\ApiIntegration\Model\Cache\Tradeaze as TradeazeCache;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\ClientAbstract;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery\CreateDelivery;
use Tradeaze\ApiIntegration\Service\InventorySourceValidator;
use Tradeaze\ApiIntegration\Service\Tradeaze as TradeazeService;

/**
 * CreateDelivery is the path taken when draft orders are turned off, and it inherits the whole
 * request from CreateDraftOrder. Which of the two runs is merchant configuration, so the date
 * handling has to be covered here as well as on the parent.
 */
class CreateDeliveryTest extends TestCase
{
    private const STORE_TIMEZONE = 'Europe/London';

    private CreateDelivery $createDelivery;
    private TradeazeService&MockObject $tradeaze;

    protected function setUp(): void
    {
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturnCallback(
            fn () => new DateTime('2026-02-06 18:30:00', new DateTimeZone(self::STORE_TIMEZONE))
        );

        $this->tradeaze = $this->createMock(TradeazeService::class);

        $this->createDelivery = new CreateDelivery(
            $this->createMock(Client::class),
            $this->createMock(TradeazeCache::class),
            $this->createMock(Config::class),
            $this->tradeaze,
            $this->createMock(LoggerInterface::class),
            $timezone,
            $this->createMock(InventorySourceValidator::class),
            $this->createMock(AddressInterfaceFactory::class)
        );
    }

    public function testKeepsTheQuotedStartTimeItInheritsFromTheParent(): void
    {
        $this->tradeaze->method('getPickUpDetails')->willReturn([]);

        $request = $this->buildRequest();

        $this->assertSame('2026-02-09T08:10:00Z', $request['startTime']);
        $this->assertSame('CAR_EVENING', $request['deliveryOptionId']);
    }

    public function testAddsThePickupDetailsTheParentDoesNot(): void
    {
        $this->tradeaze->method('getPickUpDetails')
            ->willReturn(['postCode' => 'M1 1AA', 'type' => 'PICK_UP']);

        $request = $this->buildRequest();

        $this->assertSame(['postCode' => 'M1 1AA', 'type' => 'PICK_UP'], $request['pickup']);
    }

    private function buildRequest(): array
    {
        $address = $this->createMock(OrderAddress::class);
        $address->method('getCountryId')->willReturn('GB');
        $order = $this->createMock(Order::class);
        $order->method('getShippingMethod')->willReturn('tradeaze_CAR_EVENING_202602090810');
        $order->method('getShippingAddress')->willReturn($address);
        $order->method('getIncrementId')->willReturn('000000001');
        $order->method('getAllItems')->willReturn([]);

        $params = new ReflectionProperty(ClientAbstract::class, 'params');
        $params->setValue($this->createDelivery, ['request' => $order]);

        return $this->createDelivery->buildRequest();
    }
}
