<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Plugin\Quote\Address;

use Magento\Quote\Model\Quote\Address\Rate as QuoteRate;
use Magento\Quote\Model\Quote\Address\RateResult\AbstractResult;
use PHPUnit\Framework\TestCase;
use Tradeaze\ApiIntegration\Model\DeliverySelection;
use Tradeaze\ApiIntegration\Plugin\Quote\Address\Rate;

class RateTest extends TestCase
{
    public function testTradeazeRateKeepsAbsoluteSelectionFields(): void
    {
        $source = $this->getMockBuilder(AbstractResult::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $source->method('getData')->willReturnCallback(static fn(string $field) => match ($field) {
            'carrier' => 'tradeaze',
            DeliverySelection::FIELD_DELIVERY_OPTION_ID => 'car-next-working-day',
            DeliverySelection::FIELD_DELIVERY_DATE => '2026-08-17',
            DeliverySelection::FIELD_WINDOW_START_UTC => '2026-08-17T08:30:00Z',
            default => null,
        });

        $rate = $this->getMockBuilder(QuoteRate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setData'])
            ->getMock();
        $persisted = [];
        $rate->expects($this->exactly(3))
            ->method('setData')
            ->willReturnCallback(static function (string $field, string $value) use (&$persisted, $rate) {
                $persisted[$field] = $value;
                return $rate;
            });

        (new Rate())->afterImportShippingRate($rate, $rate, $source);

        $this->assertSame([
            DeliverySelection::FIELD_DELIVERY_OPTION_ID => 'car-next-working-day',
            DeliverySelection::FIELD_DELIVERY_DATE => '2026-08-17',
            DeliverySelection::FIELD_WINDOW_START_UTC => '2026-08-17T08:30:00Z',
        ], $persisted);
    }
}
