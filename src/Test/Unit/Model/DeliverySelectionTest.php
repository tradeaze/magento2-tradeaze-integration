<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Model;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use Tradeaze\ApiIntegration\Model\DeliverySelection;

class DeliverySelectionTest extends TestCase
{
    public function testFridayQuotePreservesMondayWindowStart(): void
    {
        $selection = DeliverySelection::fromQuoteOption([
            'deliveryOptionId' => 'car-next-working-day',
            'deliveryDate' => '2026-08-17',
            'windowStart' => '2026-08-17T08:30:00.000Z',
        ]);

        $this->assertSame('car-next-working-day', $selection->getDeliveryOptionId());
        $this->assertSame('2026-08-17', $selection->getDeliveryDate());
        $this->assertSame('2026-08-17T08:30:00Z', $selection->getWindowStartUtc());
    }

    public function testSelectionCodeIsBoundedAndDistinguishesAbsoluteWindows(): void
    {
        $first = DeliverySelection::fromQuoteOption([
            'deliveryOptionId' => str_repeat('long-option-id-', 20),
            'deliveryDate' => '2026-08-17',
            'windowStart' => '2026-08-17T08:30:00Z',
        ]);
        $second = DeliverySelection::fromQuoteOption([
            'deliveryOptionId' => str_repeat('long-option-id-', 20),
            'deliveryDate' => '2026-08-17',
            'windowStart' => '2026-08-17T09:30:00Z',
        ]);

        $this->assertLessThanOrEqual(100, strlen($first->getMethodCode()));
        $this->assertSame($first->getMethodCode(), $first->getMethodCode());
        $this->assertNotSame($first->getMethodCode(), $second->getMethodCode());
    }

    public function testRejectsWindowStartWithoutAnAbsoluteTimezone(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DeliverySelection::fromQuoteOption([
            'deliveryOptionId' => 'car',
            'deliveryDate' => '2026-08-17',
            'windowStart' => '2026-08-17T08:30:00',
        ]);
    }

    public function testPersistedSelectionNormalizesDstOffsetToUtc(): void
    {
        $selection = DeliverySelection::fromPersistedData([
            DeliverySelection::FIELD_DELIVERY_OPTION_ID => 'bike',
            DeliverySelection::FIELD_DELIVERY_DATE => '2026-03-29',
            DeliverySelection::FIELD_WINDOW_START_UTC => '2026-03-29T02:30:00+01:00',
        ]);

        $this->assertSame([
            DeliverySelection::FIELD_DELIVERY_OPTION_ID => 'bike',
            DeliverySelection::FIELD_DELIVERY_DATE => '2026-03-29',
            DeliverySelection::FIELD_WINDOW_START_UTC => '2026-03-29T01:30:00Z',
        ], $selection->toPersistedData());
    }

    public function testLegacyTomorrowIsAnchoredToOrderDateAcrossRetries(): void
    {
        $selection = DeliverySelection::fromLegacyShippingMethod(
            'tradeaze_CAR_EVENING_TOMORROW0810',
            new DateTimeImmutable('2026-08-14T23:55:00Z'),
            new DateTimeZone('Europe/London'),
        );

        $this->assertSame('CAR_EVENING', $selection->getDeliveryOptionId());
        $this->assertSame('2026-08-16', $selection->getDeliveryDate());
        $this->assertSame('2026-08-16T07:10:00Z', $selection->getWindowStartUtc());
    }

    public function testDeliveryRequestUsesThePersistedOptionAndAbsoluteStart(): void
    {
        $selection = DeliverySelection::fromPersistedData([
            DeliverySelection::FIELD_DELIVERY_OPTION_ID => 'car-next-working-day',
            DeliverySelection::FIELD_DELIVERY_DATE => '2026-08-17',
            DeliverySelection::FIELD_WINDOW_START_UTC => '2026-08-17T08:30:00Z',
        ]);

        $this->assertSame([
            'deliveryOptionId' => 'car-next-working-day',
            'startTime' => '2026-08-17T08:30:00Z',
        ], $selection->toDeliveryRequestData());
    }

    public function testLegacyTodayRemainsCompatible(): void
    {
        $selection = DeliverySelection::fromLegacyShippingMethod(
            'tradeaze_BIKE_TODAY1530',
            new DateTimeImmutable('2026-01-15T10:00:00Z'),
            new DateTimeZone('Europe/London'),
        );

        $this->assertSame('2026-01-15T15:30:00Z', $selection->getWindowStartUtc());
    }

    public function testLegacyTomorrowUsesStoreDstAtTheDeliveryInstant(): void
    {
        $selection = DeliverySelection::fromLegacyShippingMethod(
            'tradeaze_BIKE_TOMORROW0230',
            new DateTimeImmutable('2026-03-28T12:00:00Z'),
            new DateTimeZone('Europe/London'),
        );

        $this->assertSame('2026-03-29T01:30:00Z', $selection->getWindowStartUtc());
    }

    public function testAcceptsLegacyQuoteResponseIdField(): void
    {
        $selection = DeliverySelection::fromQuoteOption([
            'id' => 'legacy-response-id',
            'deliveryDate' => '2026-08-17',
            'windowStart' => '2026-08-17T08:30:00Z',
        ]);

        $this->assertSame('legacy-response-id', $selection->getDeliveryOptionId());
    }

    public function testRejectsPartiallyPersistedSelection(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DeliverySelection::fromPersistedData([
            DeliverySelection::FIELD_DELIVERY_OPTION_ID => 'car',
            DeliverySelection::FIELD_WINDOW_START_UTC => '2026-08-17T08:30:00Z',
        ]);
    }

    public function testPreservesFractionalWindowStartPrecision(): void
    {
        $selection = DeliverySelection::fromQuoteOption([
            'deliveryOptionId' => 'bike',
            'deliveryDate' => '2026-08-17',
            'windowStart' => '2026-08-17T08:30:00.123456Z',
        ]);

        $this->assertSame('2026-08-17T08:30:00.123456Z', $selection->getWindowStartUtc());
    }

    public function testRejectsInvalidLegacyTime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DeliverySelection::fromLegacyShippingMethod(
            'tradeaze_CAR_TODAY2561',
            new DateTimeImmutable('2026-08-14T12:00:00Z'),
            new DateTimeZone('Europe/London'),
        );
    }

}
