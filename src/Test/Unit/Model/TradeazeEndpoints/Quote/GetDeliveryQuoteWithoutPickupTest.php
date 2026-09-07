<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Model\TradeazeEndpoints\Quote;

use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterfaceFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tradeaze\ApiIntegration\Helper\Config;
use Tradeaze\ApiIntegration\Model\Cache\Tradeaze as TradeazeCache;
use Tradeaze\ApiIntegration\Model\Carrier\ShippingMethodCode;
use Tradeaze\ApiIntegration\Model\Carrier\Tradeaze as TradeazeCarrier;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Quote\GetDeliveryQuoteWithoutPickup;
use Tradeaze\ApiIntegration\Service\InventorySourceValidator;
use Tradeaze\ApiIntegration\Service\Tradeaze as TradeazeService;

class GetDeliveryQuoteWithoutPickupTest extends TestCase
{
    private const STORE_TIMEZONE = 'Europe/London';

    private GetDeliveryQuoteWithoutPickup $quote;
    private TimezoneInterface&MockObject $timezone;

    protected function setUp(): void
    {
        $this->timezone = $this->createMock(TimezoneInterface::class);

        $config = $this->createMock(Config::class);
        $config->method('getDeliveryCutoffTimeBuffer')->willReturn('');

        $this->quote = new GetDeliveryQuoteWithoutPickup(
            $this->createMock(Client::class),
            $this->createMock(TradeazeCache::class),
            $config,
            $this->createMock(TradeazeService::class),
            $this->createMock(LoggerInterface::class),
            $this->timezone,
            $this->createMock(InventorySourceValidator::class),
            $this->createMock(AddressInterfaceFactory::class)
        );
    }

    /**
     * The Lords case: quoting on a Friday when the client has Saturday disabled returns
     * Monday options, and the method code must say Monday rather than "tomorrow"
     */
    public function testEncodesTheNextAvailableDayRatherThanARelativeFlag(): void
    {
        $this->setCurrentDate('2026-02-06 18:30:00');

        $methods = $this->parse([
            $this->option('CAR_EVENING', '2026-02-09T08:00:00.000Z', '2026-02-09'),
        ]);

        $this->assertSame('CAR_EVENING_202602090800', $methods[0]['methodCode']);
    }

    public function testEncodesASameDayOptionWithTodaysDate(): void
    {
        $this->setCurrentDate('2026-02-06 06:00:00');

        $methods = $this->parse([
            $this->option('CAR_MORNING', '2026-02-06T08:00:00.000Z', '2026-02-06'),
        ]);

        $this->assertSame('CAR_MORNING_202602060800', $methods[0]['methodCode']);
    }

    /**
     * BST - 07:00 UTC is 08:00 store local, so the code carries the local time
     */
    public function testEncodesTheStoreLocalTimeNotUtc(): void
    {
        $this->setCurrentDate('2026-06-05 06:00:00');

        $methods = $this->parse([
            $this->option('CAR_MORNING', '2026-06-08T07:00:00.000Z', '2026-06-08'),
        ]);

        $this->assertSame('CAR_MORNING_202606080800', $methods[0]['methodCode']);
    }

    /**
     * The code the customer picks must decode back to exactly the window that was quoted
     */
    public function testTheEncodedCodeRoundTripsToTheQuotedWindowStart(): void
    {
        $this->setCurrentDate('2026-02-06 18:30:00');

        $methods = $this->parse([
            $this->option('CAR_EVENING', '2026-02-09T08:00:00.000Z', '2026-02-09'),
        ]);

        // Decoded by the same class that wrote it, rather than a second copy of the format
        $decoded = ShippingMethodCode::fromShippingMethod(
            TradeazeCarrier::CARRIER_CODE . '_' . $methods[0]['methodCode']
        )->resolveStartDate(new DateTime('now', new DateTimeZone(self::STORE_TIMEZONE)));

        $quoted = new DateTime($methods[0]['methodWindowStart']);

        $this->assertSame($quoted->getTimestamp(), $decoded->getTimestamp());
    }

    /**
     * A start time that has passed by the time the order is sent is the API's to resolve
     */
    public function testEncodesAnImminentWindowWithoutMovingItForward(): void
    {
        $this->setCurrentDate('2026-02-06 07:58:00');

        $methods = $this->parse([
            $this->option('CAR_ASAP', '2026-02-06T08:00:00.000Z', '2026-02-06'),
        ]);

        $this->assertSame('CAR_ASAP_202602060800', $methods[0]['methodCode']);
    }

    public function testUnavailableOptionsAreDropped(): void
    {
        $this->setCurrentDate('2026-02-06 18:30:00');

        $methods = $this->parse([
            $this->option('CAR_EVENING', '2026-02-07T08:00:00.000Z', '2026-02-07', false),
        ]);

        $this->assertSame([], $methods);
    }

    private function option(
        string $id,
        string $windowStart,
        string $deliveryDate,
        bool $isAvailable = true
    ): array {
        return [
            'id' => $id,
            'displayName' => $id,
            'isAvailable' => $isAvailable,
            'deliveryDate' => $deliveryDate,
            'windowStart' => $windowStart,
            'cutOffTime' => ['timestamp' => '2026-02-06T20:00:00.000Z'],
            'deliveryPrice' => ['amount' => 10.0],
            'serviceCharge' => ['amount' => 2.0],
        ];
    }

    private function parse(array $options): array
    {
        $body = json_encode(['cheapestAvailableVehicleOptions' => $options]);

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

    /**
     * TimezoneInterface::date() converts an ISO string into a store-timezone DateTime,
     * and returns "now" in the store timezone when called with no argument
     */
    private function setCurrentDate(string $localNow): void
    {
        $this->timezone->method('date')->willReturnCallback(
            fn ($date = null) => $date === null
                ? new DateTime($localNow, new DateTimeZone(self::STORE_TIMEZONE))
                : (new DateTime((string) $date))->setTimezone(new DateTimeZone(self::STORE_TIMEZONE))
        );
    }
}
