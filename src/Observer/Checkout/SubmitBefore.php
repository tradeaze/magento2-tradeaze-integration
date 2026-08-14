<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Observer\Checkout;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\IntegrationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\Validator\Exception;
use Tradeaze\ApiIntegration\Api\TradeazeEndpoints\Quote\GetDeliveryQuoteInterface;
use Tradeaze\ApiIntegration\Model\DeliverySelection;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Quote\QuoteStrategyResolver;

class SubmitBefore implements ObserverInterface
{
    /** @var GetDeliveryQuoteInterface */
    protected GetDeliveryQuoteInterface $getDeliveryQuote;

    /**
     * @param QuoteStrategyResolver $quoteStrategyResolver
     */
    public function __construct(
        protected readonly QuoteStrategyResolver $quoteStrategyResolver,
    ) {
        $this->getDeliveryQuote = $this->quoteStrategyResolver->resolve();
    }

    /**
     * Validates that the selected Tradeaze shipping method is still available before order submission
     *
     * @param Observer $observer
     * @return void
     * @throws ValidatorException
     * @throws IntegrationException
     * @throws LocalizedException
     * @throws Exception
     */
    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getQuote();
        $shippingMethod = $quote->getShippingAddress()->getShippingMethod();
        if (!str_starts_with((string) $shippingMethod, 'tradeaze_')) {
            return;
        }

        $methodDataList = $this->getDeliveryQuote->execute(
            [
                'request' => $quote,
                'use_cache' => false,
            ]
        );

        $selectedCode = str_replace('tradeaze_', '', $shippingMethod);
        $selectedMethod = null;
        foreach ($methodDataList as $methodData) {
            if ($selectedCode === $methodData['methodCode']
                || $selectedCode === ($methodData['legacyMethodCode'] ?? null)
            ) {
                $selectedMethod = $methodData;
                break;
            }
        }

        if ($selectedMethod === null) {
            throw new ValidatorException(__(
                'The selected shipping method is no longer available. Select the shipping method and try again.'
            ));
        }

        try {
            $selection = DeliverySelection::fromPersistedData($selectedMethod);
        } catch (\InvalidArgumentException $e) {
            throw new ValidatorException(__('The selected Tradeaze delivery option is invalid.'), $e);
        }

        $quote->getShippingAddress()->addData($selection->toPersistedData());
    }
}
