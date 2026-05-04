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
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Quote\QuoteStrategyResolver;
use Tradeaze\ApiIntegration\Observer\Checkout\SubmitBefore;

class SubmitBeforeTest extends TestCase
{
    private SubmitBefore $observer;
    private GetDeliveryQuoteInterface&MockObject $getDeliveryQuote;

    protected function setUp(): void
    {
        $quoteStrategyResolver = $this->createMock(QuoteStrategyResolver::class);
        $this->getDeliveryQuote = $this->createMock(GetDeliveryQuoteInterface::class);
        $quoteStrategyResolver->method('resolve')->willReturn($this->getDeliveryQuote);

        $this->observer = new SubmitBefore($quoteStrategyResolver);
    }

    private function createObserverWithQuote(string $shippingMethod): Observer
    {
        $shippingAddress = $this->getMockBuilder(QuoteAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingMethod'])
            ->getMock();
        $shippingAddress->method('getShippingMethod')->willReturn($shippingMethod);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress'])
            ->getMock();
        $quote->method('getShippingAddress')->willReturn($shippingAddress);

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
        $observer = $this->createObserverWithQuote('tradeaze_CAR_TODAY1400');

        $this->getDeliveryQuote->method('execute')
            ->willReturn([
                ['methodCode' => 'CAR_TODAY1400'],
                ['methodCode' => 'VAN_TODAY1500'],
            ]);

        $this->observer->execute($observer);

        // No exception thrown means the method is still available
        $this->addToAssertionCount(1);
    }

    public function testThrowsWhenSelectedMethodNoLongerAvailable(): void
    {
        $observer = $this->createObserverWithQuote('tradeaze_CAR_TODAY1400');

        $this->getDeliveryQuote->method('execute')
            ->willReturn([
                ['methodCode' => 'VAN_TODAY1500'],
            ]);

        $this->expectException(ValidatorException::class);

        $this->observer->execute($observer);
    }

    public function testThrowsWhenNoMethodsAvailable(): void
    {
        $observer = $this->createObserverWithQuote('tradeaze_CAR_TODAY1400');

        $this->getDeliveryQuote->method('execute')
            ->willReturn([]);

        $this->expectException(ValidatorException::class);

        $this->observer->execute($observer);
    }
}
