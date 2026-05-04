<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery;

use Tradeaze\ApiIntegration\Api\TradeazeEndpoints\Delivery\CreateDeliveryInterface;

class CreateDelivery extends CreateDraftOrder implements CreateDeliveryInterface
{
    /**
     * @inheritdoc
     */
    public function buildRequest(): array
    {
        $request = parent::buildRequest();

        $request['pickup'] = $this->tradeaze->getPickUpDetails($this->resolvedSource);

        return $request;
    }

    /**
     * @inheritdoc
     */
    protected function setEndpointPath(): void
    {
        $this->endpointPath = '/v1/deliveries';
    }

    /**
     * @inheritdoc
     */
    protected function setMethod(): void
    {
        $this->method = 'POST';
    }
}
