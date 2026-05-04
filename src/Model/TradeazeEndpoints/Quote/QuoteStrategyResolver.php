<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Quote;

use Tradeaze\ApiIntegration\Api\TradeazeEndpoints\Quote\GetDeliveryQuoteInterface;
use Tradeaze\ApiIntegration\Helper\Config;

class QuoteStrategyResolver
{
    /**
     * @param GetDeliveryQuote $withPickup
     * @param GetDeliveryQuoteWithoutPickup $withoutPickup
     * @param Config $config
     */
    public function __construct(
        private readonly GetDeliveryQuote $withPickup,
        private readonly GetDeliveryQuoteWithoutPickup $withoutPickup,
        private readonly Config $config
    ) {
    }

    /**
     * Resolve based on create_draft_orders config
     *
     * @return GetDeliveryQuoteInterface
     */
    public function resolve(): GetDeliveryQuoteInterface
    {
        return $this->config->canCreateDraftOrders()
            ? $this->withoutPickup
            : $this->withPickup;
    }
}
