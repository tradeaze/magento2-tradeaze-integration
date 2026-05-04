<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Webhook;

use Magento\Framework\Validator\Exception as ValidatorException;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Store\Model\Store;
use Tradeaze\ApiIntegration\Api\TradeazeEndpoints\Webhook\CreateInterface;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\ClientAbstract;

class Create extends ClientAbstract implements CreateInterface
{
    /**
     * @inheritdoc
     */
    public function buildRequest(): array
    {
        /** @var CartInterface $requestObject */
        $requestObject = $this->params['request'];

        $secureBaseUrl = $this->tradeazeConfig->getScopeConfigValue(Store::XML_PATH_SECURE_BASE_URL);
        return [
            'eventId'    => $requestObject['eventId'],
            'isActive'   => true,
            'webhookUrl' => $secureBaseUrl . $requestObject['webhookUrl'],
        ];
    }

    /**
     * @inheritdoc
     */
    protected function validateParams(): void
    {
        if (! isset($this->params['request'])) {
            throw new ValidatorException(__('Missing required param "request"'));
        }

        $request = $this->params['request'];

        if (!isset($request['eventId'])) {
            throw new ValidatorException(__('Missing required request param "eventId"'));
        }

        if (!isset($request['webhookUrl'])) {
            throw new ValidatorException(__('Missing required request param "webhookUrl"'));
        }
    }

    /**
     * @inheritdoc
     */
    protected function setEndpointPath(): void
    {
        $this->endpointPath = '/v1/webhooks';
    }

    /**
     * @inheritdoc
     */
    protected function setMethod(): void
    {
        $this->method = 'POST';
    }
}
