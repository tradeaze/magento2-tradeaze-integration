<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Observer\Checkout;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\ValidatorException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tradeaze\ApiIntegration\Api\TradeazeEndpoints\Quote\GetDeliveryQuoteInterface;
use Tradeaze\ApiIntegration\Model\DeliverySelection;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Quote\QuoteStrategyResolver;
use Tradeaze\ApiIntegration\Observer\Checkout\SubmitBefore;

class SubmitBeforeTest extends TestCase
{
    private SubmitBefore $observer;
    private GetDeliveryQuoteInterface&MockObject $getDeliveryQuote;
    private QuoteAddress&MockObject $shippingAddress;

    protected function setUp(): void
    {
        $quoteStrategyResolver = $this->createMock(QuoteStrategyResolver::class);
        $this->getDeliveryQuote = $this->createMock(GetDeliveryQuoteInterface::class);
        $quoteStrategyResolver->method('resolve')->willReturn($this->getDeliveryQuote);

        $this->observer = new SubmitBefore($quoteStrategyResolver);
    }

    private function createObserverWithQuote(string $shippingMethod): Observer
    {
        $this->shippingAddress = $this->getMockBuilder(QuoteAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingMethod', 'addData'])
            ->getMock();
        $this->shippingAddress->method('getShippingMethod')->willReturn($shippingMethod);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress'])
            ->getMock();
        $quote->method('getShippingAddress')->willReturn($this->shippingAddress);

        $event = new DataObject(['quote' => $quote]);

        $observer = $this->getMockBuilder(Observer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEvent'])
            ->getMock();
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }

    public function testSkipsNonTradeazeShippingMethods(): void
    {
        $observer = $this->createObserverWithQuote('flatrate_flatrate');

        $this->getDeliveryQuote->expects($this->never())->method('execute');

        $this->observer->execute($observer);
    }

    public function testPassesWhenSelectedMethodIsStillAvailable(): void
    {
        $observer = $this->createObserverWithQuote('tradeaze_delivery_abc');

        $this->getDeliveryQuote->expects($this->once())
            ->method('execute')
            ->with($this->callback(fn(array $params) => $params['use_cache'] === false))
            ->willReturn([
                [
                    'methodCode' => 'delivery_abc',
                    DeliverySelection::FIELD_DELIVERY_OPTION_ID => 'car-monday',
                    DeliverySelection::FIELD_DELIVERY_DATE => '2026-08-17',
                    DeliverySelection::FIELD_WINDOW_START_UTC => '2026-08-17T08:30:00Z',
                ],
            ]);

        $this->shippingAddress->expects($this->once())
            ->method('addData')
            ->with([
                DeliverySelection::FIELD_DELIVERY_OPTION_ID => 'car-monday',
                DeliverySelection::FIELD_DELIVERY_DATE => '2026-08-17',
                DeliverySelection::FIELD_WINDOW_START_UTC => '2026-08-17T08:30:00Z',
            ]);

        $this->observer->execute($observer);

        // No exception thrown means the method is still available
        $this->addToAssertionCount(1);
    }

    public function testThrowsWhenSelectedMethodNoLongerAvailable(): void
    {
        $observer = $this->createObserverWithQuote('tradeaze_delivery_expired');

        $this->getDeliveryQuote->method('execute')
            ->willReturn([
                ['methodCode' => 'delivery_other'],
            ]);

        $this->expectException(ValidatorException::class);

        $this->observer->execute($observer);
    }

    public function testThrowsWhenNoMethodsAvailable(): void
    {
        $observer = $this->createObserverWithQuote('tradeaze_delivery_expired');

        $this->getDeliveryQuote->method('execute')
            ->willReturn([]);

        $this->expectException(ValidatorException::class);

        $this->observer->execute($observer);
    }

    public function testLegacyTodayAndTomorrowCodesRemainSelectable(): void
    {
        $observer = $this->createObserverWithQuote('tradeaze_CAR_TOMORROW0930');

        $this->getDeliveryQuote->method('execute')->willReturn([[
            'methodCode' => 'delivery_new-code',
            'legacyMethodCode' => 'CAR_TOMORROW0930',
            DeliverySelection::FIELD_DELIVERY_OPTION_ID => 'CAR',
            DeliverySelection::FIELD_DELIVERY_DATE => '2026-08-15',
            DeliverySelection::FIELD_WINDOW_START_UTC => '2026-08-15T08:20:00Z',
        ]]);
        $this->shippingAddress->expects($this->once())->method('addData');

        $this->observer->execute($observer);
    }

    public function testThrowsWhenAvailableMethodHasInvalidAbsoluteFields(): void
    {
        $observer = $this->createObserverWithQuote('tradeaze_delivery_invalid');

        $this->getDeliveryQuote->method('execute')->willReturn([[
            'methodCode' => 'delivery_invalid',
            DeliverySelection::FIELD_DELIVERY_OPTION_ID => 'CAR',
            DeliverySelection::FIELD_DELIVERY_DATE => 'not-a-date',
            DeliverySelection::FIELD_WINDOW_START_UTC => '2026-08-15T08:20:00Z',
        ]]);

        $this->expectException(ValidatorException::class);

        $this->observer->execute($observer);
    }
}
