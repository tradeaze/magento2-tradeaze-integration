<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Plugin\Quote\Address;

use Magento\Quote\Model\Quote\Address\Rate as QuoteRate;
use Magento\Quote\Model\Quote\Address\RateResult\AbstractResult;
use Tradeaze\ApiIntegration\Model\DeliverySelection;

class Rate
{
    /**
     * Persist Tradeaze's absolute selection fields with each Magento quote rate.
     *
     * @param QuoteRate $subject
     * @param QuoteRate $result
     * @param AbstractResult $rate
     * @return QuoteRate
     */
    public function afterImportShippingRate(
        QuoteRate $subject,
        QuoteRate $result,
        AbstractResult $rate,
    ): QuoteRate {
        if ($rate->getData('carrier') !== 'tradeaze') {
            return $result;
        }

        foreach (DeliverySelection::PERSISTED_FIELDS as $field) {
            $result->setData($field, $rate->getData($field));
        }

        return $result;
    }
}
