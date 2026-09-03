<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Model\TradeazeEndpoints\Delivery;

use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\Validator\Exception as ValidatorException;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterfaceFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Psr\Log\LoggerInterface;
use Tradeaze\ApiIntegration\Helper\Config;
use Tradeaze\ApiIntegration\Model\Cache\Tradeaze as TradeazeCache;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\ClientAbstract;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery\CreateDraftOrder;
use Tradeaze\ApiIntegration\Service\InventorySourceValidator;
use Tradeaze\ApiIntegration\Service\Tradeaze as TradeazeService;

class CreateDraftOrderTest extends TestCase
{
    private const STORE_TIMEZONE = 'Europe/London';

    private CreateDraftOrder $createDraftOrder;
    private TimezoneInterface&MockObject $timezone;
    private string $currentDate = '2026-02-06 18:30:00';

    protected function setUp(): void
    {
        $this->timezone = $this->createMock(TimezoneInterface::class);

        // A fresh DateTime per call, matching TimezoneInterface::date(), which the code mutates.
        // Reads $currentDate at call time so a test can move "now" mid-test.
        $this->timezone->method('date')->willReturnCallback(
            fn () => new DateTime($this->currentDate, new DateTimeZone(self::STORE_TIMEZONE))
        );

        $this->createDraftOrder = new CreateDraftOrder(
            $this->createMock(Client::class),
            $this->createMock(TradeazeCache::class),
            $this->createMock(Config::class),
            $this->createMock(TradeazeService::class),
            $this->createMock(LoggerInterface::class),
            $this->timezone,
            $this->createMock(InventorySourceValidator::class),
            $this->createMock(AddressInterfaceFactory::class)
        );
    }

    public function testUsesTheAbsoluteDateEncodedInTheShippingMethod(): void
    {
        // Quote taken on a Friday for the next available day, which is the Monday
        $this->setCurrentDate('2026-02-06 18:30:00');

        $request = $this->buildRequestFor('tradeaze_CAR_EVENING_202602090810');

        $this->assertSame('2026-02-09T08:10:00Z', $request['startTime']);
        $this->assertSame('CAR_EVENING', $request['deliveryOptionId']);
    }

    public function testTheDateIsUnaffectedByWhenTheOrderIsPlaced(): void
    {
        $this->setCurrentDate('2026-02-06 18:30:00');
        $quotedOnFriday = $this->buildRequestFor('tradeaze_CAR_202602090810');

        // Same order sent by the retry cron three days later
        $this->setCurrentDate('2026-02-09 06:00:00');
        $sentOnMonday = $this->buildRequestFor('tradeaze_CAR_202602090810');

        $this->assertSame($quotedOnFriday['startTime'], $sentOnMonday['startTime']);
        $this->assertSame('2026-02-09T08:10:00Z', $sentOnMonday['startTime']);
    }

    /**
     * BST - the store is UTC+1, so the UTC start time is an hour behind the quoted local time
     */
    public function testConvertsTheStoreLocalQuotedTimeToUtc(): void
    {
        $this->setCurrentDate('2026-06-05 10:00:00');

        $request = $this->buildRequestFor('tradeaze_CAR_202606080810');

        $this->assertSame('2026-06-08T07:10:00Z', $request['startTime']);
    }

    /**
     * Orders placed before the format change and not yet sent still go through
     */
    public function testFallsBackToTheLegacyTodayTomorrowFormat(): void
    {
        $this->setCurrentDate('2026-02-06 18:30:00');

        $this->assertSame(
            '2026-02-06T14:00:00Z',
            $this->buildRequestFor('tradeaze_CAR_TODAY1400')['startTime']
        );
        $this->assertSame(
            '2026-02-07T14:00:00Z',
            $this->buildRequestFor('tradeaze_CAR_TOMORROW1400')['startTime']
        );
    }

    /**
     * A winter delivery date must resolve as GMT even when the order is sent during BST,
     * and vice versa - the offset belongs to the quoted date, not to "now"
     */
    public function testResolvesTheOffsetOfTheQuotedDateNotOfTheCurrentDate(): void
    {
        $this->setCurrentDate('2026-07-01 12:00:00');
        $this->assertSame(
            '2026-01-15T09:00:00Z',
            $this->buildRequestFor('tradeaze_CAR_202601150900')['startTime']
        );

        $this->setCurrentDate('2026-01-15 12:00:00');
        $this->assertSame(
            '2026-07-15T08:00:00Z',
            $this->buildRequestFor('tradeaze_CAR_202607150900')['startTime']
        );
    }

    /**
     * Delivery option ids carry underscores and may carry digits, so the date has to be taken
     * from the end of the code rather than from the first underscore-delimited number
     */
    public function testSeparatesTheDateFromAnOptionIdContainingDigits(): void
    {
        $request = $this->buildRequestFor('tradeaze_CAR_2_LARGE_9_202602090810');

        $this->assertSame('CAR_2_LARGE_9', $request['deliveryOptionId']);
        $this->assertSame('2026-02-09T08:10:00Z', $request['startTime']);
    }

    /**
     * DateTime would roll an impossible date forward onto a real one, silently delivering on a
     * day that was never quoted
     *
     * @dataProvider impossibleDateProvider
     */
    public function testThrowsForADateThatCannotExist(string $shippingMethod): void
    {
        $this->expectException(ValidatorException::class);

        $this->buildRequestFor($shippingMethod);
    }

    public static function impossibleDateProvider(): array
    {
        return [
            'month 13' => ['tradeaze_CAR_202613010810'],
            '31 February' => ['tradeaze_CAR_202602310810'],
            'hour 99' => ['tradeaze_CAR_202602099900'],
            'minute 75' => ['tradeaze_CAR_202602090875'],
            'day 00' => ['tradeaze_CAR_202602000810'],
        ];
    }

    public function testThrowsForAnUnrecognisedShippingMethodFormat(): void
    {
        $this->setCurrentDate('2026-02-06 18:30:00');

        $this->expectException(ValidatorException::class);

        $this->buildRequestFor('tradeaze_CAR_SOMEDAY');
    }

    private function setCurrentDate(string $localDateTime): void
    {
        $this->currentDate = $localDateTime;
    }

    private function buildRequestFor(string $shippingMethod): array
    {
        $address = $this->createMock(OrderAddress::class);
        $address->method('getCountryId')->willReturn('GB');
        $order = $this->createMock(Order::class);
        $order->method('getShippingMethod')->willReturn($shippingMethod);
        $order->method('getShippingAddress')->willReturn($address);
        $order->method('getIncrementId')->willReturn('000000001');
        $order->method('getAllItems')->willReturn([]);

        // buildRequest() reads the protected $params that execute() would normally populate
        $params = new ReflectionProperty(ClientAbstract::class, 'params');
        $params->setValue($this->createDraftOrder, ['request' => $order]);

        return $this->createDraftOrder->buildRequest();
    }
}
