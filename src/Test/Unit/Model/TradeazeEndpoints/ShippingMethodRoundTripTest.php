<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Model\TradeazeEndpoints;

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
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery\CreateDraftOrder;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Quote\GetDeliveryQuoteWithoutPickup;
use Tradeaze\ApiIntegration\Service\InventorySourceValidator;
use Tradeaze\ApiIntegration\Service\Tradeaze as TradeazeService;

/**
 * The shipping method code is produced by the quote and consumed by the delivery request, with
 * nothing between them to validate it. Each side has its own tests; this pins the contract, so
 * the two cannot drift into agreeing separately but not with each other.
 */
class ShippingMethodRoundTripTest extends TestCase
{
    private const STORE_TIMEZONE = 'Europe/London';

    private GetDeliveryQuoteWithoutPickup $quote;
    private CreateDraftOrder $delivery;
    private TimezoneInterface&MockObject $timezone;
    private string $currentDate = '2026-02-06 18:30:00';

    protected function setUp(): void
    {
        $this->timezone = $this->createMock(TimezoneInterface::class);
        $this->timezone->method('date')->willReturnCallback(
            fn ($date = null) => $date === null
                ? new DateTime($this->currentDate, new DateTimeZone(self::STORE_TIMEZONE))
                : (new DateTime((string) $date))->setTimezone(new DateTimeZone(self::STORE_TIMEZONE))
        );

        $config = $this->createMock(Config::class);
        $config->method('getDeliveryCutoffTimeBuffer')->willReturn('');

        $args = [
            $this->createMock(Client::class),
            $this->createMock(TradeazeCache::class),
            $config,
            $this->createMock(TradeazeService::class),
            $this->createMock(LoggerInterface::class),
            $this->timezone,
            $this->createMock(InventorySourceValidator::class),
            $this->createMock(AddressInterfaceFactory::class),
        ];

        $this->quote = new GetDeliveryQuoteWithoutPickup(...$args);
        $this->delivery = new CreateDraftOrder(...$args);
    }

    /**
     * @dataProvider windowProvider
     */
    public function testAQuotedWindowSurvivesEncodingAndDecoding(
        string $windowStart,
        string $deliveryDate,
        string $quotedAt,
        string $sentAt
    ): void {
        $this->currentDate = $quotedAt;
        $methods = $this->quoteFor($windowStart, $deliveryDate, $quotedAt);
        $this->assertCount(1, $methods, 'the option should have been offered');

        // What Magento stores on the order is the carrier code plus the method code
        $shippingMethod = 'tradeaze_' . $methods[0]['methodCode'];

        $this->currentDate = $sentAt;
        $startTime = $this->deliveryRequestFor($shippingMethod)['startTime'];

        $this->assertSame(
            (new DateTime($windowStart))->getTimestamp(),
            (new DateTime($startTime))->getTimestamp(),
            'the delivery must start at the instant the customer was quoted'
        );
        $this->assertSame(
            $deliveryDate,
            (new DateTime($startTime))->setTimezone(new DateTimeZone(self::STORE_TIMEZONE))->format('Y-m-d'),
            'and on the day the API said it would'
        );
    }

    public static function windowProvider(): array
    {
        return [
            //                                  windowStart                 deliveryDate  quotedAt               sentAt
            // Quoted Friday evening for the next working day, sent once payment clears
            'next working day' => ['2026-02-09T08:00:00.000Z', '2026-02-09', '2026-02-06 18:30:00', '2026-02-06 18:31:00'],
            // The same code picked up by the retry cron days later, after the window has passed
            'sent days later' => ['2026-02-09T08:00:00.000Z', '2026-02-09', '2026-02-06 18:30:00', '2026-02-11 06:00:00'],
            'same day' => ['2026-02-06T20:00:00.000Z', '2026-02-06', '2026-02-06 18:30:00', '2026-02-06 18:35:00'],
            // 07:00 UTC is 08:00 local under BST, and the code carries local time
            'during BST' => ['2026-06-08T07:00:00.000Z', '2026-06-08', '2026-06-05 06:00:00', '2026-06-05 06:10:00'],
            // A GMT window sent while the clocks are forward - the offset belongs to the window
            'GMT window sent during BST' => ['2026-01-15T09:00:00.000Z', '2026-01-15', '2026-01-14 12:00:00', '2026-07-01 12:00:00'],
            'last minute of the day' => ['2026-02-09T23:52:00.000Z', '2026-02-09', '2026-02-06 18:30:00', '2026-02-06 18:31:00'],
        ];
    }

    private function quoteFor(string $windowStart, string $deliveryDate, string $quotedAt): array
    {
        // An hour after the quote, so the option is inside its cut-off and gets offered
        $cutOff = (new DateTime($quotedAt, new DateTimeZone(self::STORE_TIMEZONE)))
            ->modify('+1 hour')
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');

        $body = json_encode(['cheapestAvailableVehicleOptions' => [[
            'id' => 'CAR_EVENING',
            'displayName' => 'Evening',
            'isAvailable' => true,
            'deliveryDate' => $deliveryDate,
            'windowStart' => $windowStart,
            'cutOffTime' => ['timestamp' => $cutOff],
            'deliveryPrice' => ['amount' => 10.0],
            'serviceCharge' => ['amount' => 2.0],
        ]]]);

        $stream = new class ($body) {
            public function __construct(private string $body) {}
            public function getContents(): string { return $this->body; }
        };
        $response = new class ($stream) {
            public function __construct(private object $stream) {}
            public function getBody(): object { return $this->stream; }
        };

        return $this->quote->parseResponse($response);
    }

    private function deliveryRequestFor(string $shippingMethod): array
    {
        $address = $this->createMock(OrderAddress::class);
        $address->method('getCountryId')->willReturn('GB');
        $order = $this->createMock(Order::class);
        $order->method('getShippingMethod')->willReturn($shippingMethod);
        $order->method('getShippingAddress')->willReturn($address);
        $order->method('getIncrementId')->willReturn('000000001');
        $order->method('getAllItems')->willReturn([]);

        $params = new ReflectionProperty(ClientAbstract::class, 'params');
        $params->setValue($this->delivery, ['request' => $order]);

        return $this->delivery->buildRequest();
    }
}
