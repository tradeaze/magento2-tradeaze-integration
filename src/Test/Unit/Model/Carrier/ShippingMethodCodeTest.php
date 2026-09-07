<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Model\Carrier;

use DateTime;
use DateTimeZone;
use Magento\Framework\Validator\Exception as ValidatorException;
use PHPUnit\Framework\TestCase;
use Tradeaze\ApiIntegration\Model\Carrier\ShippingMethodCode;

/**
 * The format contract itself. The quote and the delivery request both go through this class,
 * so what it accepts and emits is the whole agreement between them.
 */
class ShippingMethodCodeTest extends TestCase
{
    private const STORE_TIMEZONE = 'Europe/London';

    public function testEncodesTheQuotedWindowStartAsAnAbsoluteDate(): void
    {
        $code = ShippingMethodCode::forQuotedWindowStart('CAR_EVENING', $this->storeLocal('2026-02-09 08:00:00'));

        $this->assertSame('CAR_EVENING_202602090800', $code->methodCode());
    }

    public function testPadsSingleDigitDateParts(): void
    {
        $code = ShippingMethodCode::forQuotedWindowStart('VAN', $this->storeLocal('2026-01-05 09:07:00'));

        $this->assertSame('VAN_202601050907', $code->methodCode());
    }

    public function testAnEncodedCodeDecodesBackToTheSameWindow(): void
    {
        $quoted = $this->storeLocal('2026-02-09 08:14:00');
        $encoded = ShippingMethodCode::forQuotedWindowStart('CAR_EVENING', $quoted)->methodCode();

        $decoded = ShippingMethodCode::fromShippingMethod('tradeaze_' . $encoded);

        $this->assertSame('CAR_EVENING', $decoded->deliveryOptionId());
        $this->assertSame(
            $quoted->getTimestamp(),
            $decoded->resolveStartDate($this->storeLocal('2026-02-06 18:30:00'))->getTimestamp()
        );
    }

    /**
     * Option ids contain underscores and digits, so the split must not be ambiguous
     */
    public function testSplitsAnOptionIdThatContainsDigitsAndUnderscores(): void
    {
        $code = ShippingMethodCode::fromShippingMethod('tradeaze_CAR_2_EVENING_202602090800');

        $this->assertSame('CAR_2_EVENING', $code->deliveryOptionId());
    }

    /**
     * The date is resolved in the store timezone of the quoted day, not of the day it is sent
     */
    public function testResolvesTheOffsetOfTheQuotedDayNotTheCurrentOne(): void
    {
        $code = ShippingMethodCode::fromShippingMethod('tradeaze_CAR_202601150900');

        // A GMT window read back while the clocks are forward
        $startDate = $code->resolveStartDate($this->storeLocal('2026-07-01 12:00:00'));

        $this->assertSame('2026-01-15T09:00:00+00:00', $startDate->format('c'));
    }

    public function testResolvesAWindowQuotedUnderBst(): void
    {
        $code = ShippingMethodCode::fromShippingMethod('tradeaze_CAR_202606080800');

        $startDate = $code->resolveStartDate($this->storeLocal('2026-01-05 12:00:00'));

        $this->assertSame('2026-06-08T08:00:00+01:00', $startDate->format('c'));
    }

    /**
     * @dataProvider impossibleDateProvider
     */
    public function testRejectsADateThatCannotExist(string $shippingMethod): void
    {
        $this->expectException(ValidatorException::class);

        ShippingMethodCode::fromShippingMethod($shippingMethod);
    }

    public static function impossibleDateProvider(): array
    {
        return [
            '31 February' => ['tradeaze_CAR_202602310800'],
            'month 13' => ['tradeaze_CAR_202613010800'],
            'day zero' => ['tradeaze_CAR_202602000800'],
            'hour 24' => ['tradeaze_CAR_202602092400'],
            'minute 60' => ['tradeaze_CAR_202602090860'],
        ];
    }

    /**
     * @dataProvider unparseableProvider
     */
    public function testRejectsACodeItCannotRead(string $shippingMethod): void
    {
        $this->expectException(ValidatorException::class);

        ShippingMethodCode::fromShippingMethod($shippingMethod);
    }

    public static function unparseableProvider(): array
    {
        return [
            'another carrier' => ['flatrate_flatrate'],
            'no suffix' => ['tradeaze_CAR'],
            'not a date' => ['tradeaze_CAR_LATER'],
            'too few digits' => ['tradeaze_CAR_2026020908'],
            'empty option id' => ['tradeaze_202602090800'],
        ];
    }

    /**
     * The deprecated format still has to go through, so orders placed before the absolute
     * format and not yet sent are not stranded
     */
    public function testStillReadsTheDeprecatedRelativeFormat(): void
    {
        $code = ShippingMethodCode::fromShippingMethod('tradeaze_CAR_EVENING_TOMORROW0800');

        $this->assertSame('CAR_EVENING', $code->deliveryOptionId());
        $this->assertFalse($code->carriesADate());
        $this->assertSame(
            '2026-02-07 08:00:00',
            $code->resolveStartDate($this->storeLocal('2026-02-06 18:30:00'))->format('Y-m-d H:i:s')
        );
    }

    public function testTheDeprecatedTodayFormatResolvesToTheCurrentDay(): void
    {
        $code = ShippingMethodCode::fromShippingMethod('tradeaze_CAR_TODAY2000');

        $this->assertSame(
            '2026-02-06 20:00:00',
            $code->resolveStartDate($this->storeLocal('2026-02-06 18:30:00'))->format('Y-m-d H:i:s')
        );
    }

    /**
     * The bug this format replaced: a relative code cannot say which day it meant
     */
    public function testOnlyTheAbsoluteFormatSurvivesBeingSentOnALaterDay(): void
    {
        $absolute = ShippingMethodCode::fromShippingMethod('tradeaze_CAR_202602090800');
        $relative = ShippingMethodCode::fromShippingMethod('tradeaze_CAR_TOMORROW0800');

        $sentLater = $this->storeLocal('2026-02-11 06:00:00');

        $this->assertSame('2026-02-09', $absolute->resolveStartDate($sentLater)->format('Y-m-d'));
        $this->assertSame('2026-02-12', $relative->resolveStartDate($sentLater)->format('Y-m-d'));
    }

    public function testResolvingDoesNotMutateTheDatePassedIn(): void
    {
        $now = $this->storeLocal('2026-02-06 18:30:00');

        ShippingMethodCode::fromShippingMethod('tradeaze_CAR_202602090800')->resolveStartDate($now);

        $this->assertSame('2026-02-06 18:30:00', $now->format('Y-m-d H:i:s'));
    }

    private function storeLocal(string $dateTime): DateTime
    {
        return new DateTime($dateTime, new DateTimeZone(self::STORE_TIMEZONE));
    }
}
