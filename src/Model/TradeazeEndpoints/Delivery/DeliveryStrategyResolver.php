<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery;

use Tradeaze\ApiIntegration\Api\TradeazeEndpoints\Delivery\CreateDeliveryInterface;
use Tradeaze\ApiIntegration\Helper\Config;

class DeliveryStrategyResolver
{
    /**
     * @param CreateDelivery $withPickup
     * @param CreateDraftOrder $withoutPickup
     * @param Config $config
     */
    public function __construct(
        private readonly CreateDelivery $withPickup,
        private readonly CreateDraftOrder $withoutPickup,
        private readonly Config $config
    ) {
    }

    /**
     * Resolve based on create_draft_orders config
     *
     * @return CreateDeliveryInterface
     */
    public function resolve(): CreateDeliveryInterface
    {
        return $this->config->canCreateDraftOrders()
            ? $this->withoutPickup
            : $this->withPickup;
    }
}
