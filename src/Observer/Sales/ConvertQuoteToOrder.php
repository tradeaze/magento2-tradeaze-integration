<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Observer\Sales;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Tradeaze\ApiIntegration\Model\DeliverySelection;

class ConvertQuoteToOrder implements ObserverInterface
{
    /**
     * Copy the validated Tradeaze delivery selection from the quote to the order.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getData('quote');
        $order = $observer->getEvent()->getData('order');
        $shippingAddress = $quote->getShippingAddress();

        if (!str_starts_with((string) $shippingAddress->getShippingMethod(), 'tradeaze_')) {
            return;
        }

        $selectionData = [];
        foreach (DeliverySelection::PERSISTED_FIELDS as $field) {
            $selectionData[$field] = $shippingAddress->getData($field);
        }

        $selection = DeliverySelection::fromPersistedData($selectionData);
        $order->addData($selection->toPersistedData());
    }
}
