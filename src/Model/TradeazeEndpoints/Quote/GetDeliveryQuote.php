<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Quote;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Tradeaze\ApiIntegration\Api\TradeazeEndpoints\Quote\GetDeliveryQuoteInterface;

class GetDeliveryQuote extends GetDeliveryQuoteWithoutPickup implements GetDeliveryQuoteInterface
{

    /**
     * @inheritdoc
     */
    public function buildRequest(): array
    {
        $request = parent::buildRequest();

        try {
            $request['pickup'] = [
                'postcode' => $this->tradeaze->getPickUpPostcode($this->resolvedSource)
            ];
        } catch (InputException|LocalizedException $e) {
            //the request will fail because of data issues and this will give information about the cause
            $this->logger->error($e->getMessage());
        }

        return $request;
    }

    /**
     * @inheritdoc
     */
    protected function setEndpointPath(): void
    {
        $this->endpointPath = '/v1/deliveries/quote';
    }
}
