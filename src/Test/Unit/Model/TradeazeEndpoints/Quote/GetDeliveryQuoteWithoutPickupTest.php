<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Model\TradeazeEndpoints\Quote;

use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterfaceFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tradeaze\ApiIntegration\Helper\Config;
use Tradeaze\ApiIntegration\Model\Cache\Tradeaze as TradeazeCache;
use Tradeaze\ApiIntegration\Model\DeliverySelection;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Quote\GetDeliveryQuoteWithoutPickup;
use Tradeaze\ApiIntegration\Service\InventorySourceValidator;
use Tradeaze\ApiIntegration\Service\Tradeaze;

class GetDeliveryQuoteWithoutPickupTest extends TestCase
{
    private function createEndpoint(string $now): GetDeliveryQuoteWithoutPickup
    {
        $config = $this->createMock(Config::class);
        $config->method('getDeliveryCutoffTimeBuffer')->willReturn(15);

        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturnCallback(static function ($date = null) use ($now) {
            $storeTimezone = new DateTimeZone('Europe/London');
            if ($date === null) {
                return (new DateTime($now))->setTimezone($storeTimezone);
            }

            return (new DateTime((string) $date))->setTimezone($storeTimezone);
        });

        return new GetDeliveryQuoteWithoutPickup(
            $this->createMock(Client::class),
            $this->createMock(TradeazeCache::class),
            $config,
            $this->createMock(Tradeaze::class),
            $this->createMock(LoggerInterface::class),
            $timezone,
            $this->createMock(InventorySourceValidator::class),
            $this->createMock(AddressInterfaceFactory::class),
        );
    }

    private function createResponse(array $optionData = []): Response
    {
        $option = array_merge([
            'deliveryOptionId' => 'car-next-working-day',
            'deliveryDate' => '2026-08-17',
            'windowStart' => '2026-08-17T08:30:00.000Z',
            'cutOffTime' => ['timestamp' => '2026-08-17T08:00:00.000Z'],
            'isAvailable' => true,
            'displayName' => 'Monday morning',
            'deliveryPrice' => ['amount' => 10],
            'serviceCharge' => ['amount' => 2],
        ], $optionData);

        return new Response(200, [], json_encode([
            'cheapestAvailableVehicleOptions' => [$option],
        ]));
    }

    public function testFridayQuoteKeepsMondayAsAnAbsoluteSelection(): void
    {
        $endpoint = $this->createEndpoint('2026-08-14T12:00:00+01:00');

        $methods = $endpoint->parseResponse($this->createResponse());

        $this->assertCount(1, $methods);
        $this->assertStringStartsWith('delivery_', $methods[0]['methodCode']);
        $this->assertSame('car-next-working-day', $methods[0][DeliverySelection::FIELD_DELIVERY_OPTION_ID]);
        $this->assertSame('2026-08-17', $methods[0][DeliverySelection::FIELD_DELIVERY_DATE]);
        $this->assertSame('2026-08-17T08:30:00Z', $methods[0][DeliverySelection::FIELD_WINDOW_START_UTC]);
    }

    public function testFutureDeliveryIsUnavailableAfterItsAbsoluteCutoff(): void
    {
        $endpoint = $this->createEndpoint('2026-08-14T12:00:00+01:00');
        $response = $this->createResponse([
            'cutOffTime' => ['timestamp' => '2026-08-14T12:10:00+01:00'],
        ]);

        $this->assertSame([], $endpoint->parseResponse($response));
    }

    public function testCutoffBufferUsesAbsoluteInstantsAcrossDstChange(): void
    {
        $endpoint = $this->createEndpoint('2026-03-29T00:50:00Z');
        $response = $this->createResponse([
            'deliveryDate' => '2026-03-29',
            'windowStart' => '2026-03-29T02:30:00+01:00',
            'cutOffTime' => ['timestamp' => '2026-03-29T02:10:00+01:00'],
        ]);

        $this->assertCount(1, $endpoint->parseResponse($response));
    }
}
