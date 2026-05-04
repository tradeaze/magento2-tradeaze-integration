<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery;

use Magento\Framework\Validator\Exception as ValidatorException;
use Magento\Sales\Model\Order;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\ClientAbstract;

class CancelDelivery extends ClientAbstract
{

    /**
     * @inheritDoc
     */
    public function buildRequest(): array
    {
        /** @var Order $requestObject */
        $requestObject = $this->params['request'];

        $this->endpointPath .= '/' . $requestObject->getTradeazeOrderId();

        return [
            'id' => $requestObject->getTradeazeOrderId()
        ];
    }

    /**
     * @inheritdoc
     */
    protected function setEndpointPath(): void
    {
        $this->endpointPath = '/v1/deliveries';
    }

    /**
     * @inheritDoc
     */
    protected function setMethod(): void
    {
        $this->method = 'DELETE';
    }

    /**
     * @inheritDoc
     */
    protected function validateParams(): void
    {

        if (! isset($this->params['request'])) {
            throw new ValidatorException(__('Missing required param "request"'));
        }

        if (! $this->params['request'] instanceof Order) {
            throw new ValidatorException(
                __('Parameter "request" must be instance of \Magento\Sales\Model\Order')
            );
        }

        $order = $this->params['request'];
        if (! $order->getTradeazeOrderId()) {
            throw new ValidatorException(
                __('No Tradeaze Order ID found')
            );
        }
    }
}
