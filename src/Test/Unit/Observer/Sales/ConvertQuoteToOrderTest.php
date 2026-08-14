<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Observer\Sales;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Tradeaze\ApiIntegration\Model\DeliverySelection;
use Tradeaze\ApiIntegration\Observer\Sales\ConvertQuoteToOrder;

class ConvertQuoteToOrderTest extends TestCase
{
    public function testAbsoluteSelectionIsCopiedToTheOrder(): void
    {
        $selectionData = [
            DeliverySelection::FIELD_DELIVERY_OPTION_ID => 'car-next-working-day',
            DeliverySelection::FIELD_DELIVERY_DATE => '2026-08-17',
            DeliverySelection::FIELD_WINDOW_START_UTC => '2026-08-17T08:30:00Z',
        ];
        $shippingAddress = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingMethod', 'getData'])
            ->getMock();
        $shippingAddress->method('getShippingMethod')->willReturn('tradeaze_delivery_abc');
        $shippingAddress->method('getData')
            ->willReturnCallback(static fn(string $field) => $selectionData[$field] ?? null);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress'])
            ->getMock();
        $quote->method('getShippingAddress')->willReturn($shippingAddress);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addData'])
            ->getMock();
        $order->expects($this->once())->method('addData')->with($selectionData);

        $event = new DataObject(['quote' => $quote, 'order' => $order]);
        $observer = $this->getMockBuilder(Observer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEvent'])
            ->getMock();
        $observer->method('getEvent')->willReturn($event);

        (new ConvertQuoteToOrder())->execute($observer);
    }
}
