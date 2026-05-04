<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Controller\Adminhtml\System\Config;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Throwable;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Webhook\Create;

class CreateWebhook extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Tradeaze_ApiIntegration::config_tradeaze_api_integration';

    /** @see src/etc/webapi.xml */
    public const WEBHOOK_ENDPOINTS = [
        'ORDER_CONFIRMED' =>  'rest/V1/tradeaze-api-integration/orderconfirmed',
        'ORDER_CANCELLED' =>  'rest/V1/tradeaze-api-integration/ordercancelled',
        'ORDER_UPDATED'   =>  'rest/V1/tradeaze-api-integration/orderupdated',
        'DROPOFF_COMPLETE' => 'rest/V1/tradeaze-api-integration/dropoffcomplete'
    ];

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param Create $webhookCreate
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Create $webhookCreate
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $result   = $this->jsonFactory->create();

        try {
            foreach (static::WEBHOOK_ENDPOINTS as $event => $endpoint) {
                $this->webhookCreate->execute(
                    [
                        'request'=> [
                            'eventId' => $event,
                            'webhookUrl' => $endpoint
                        ]
                    ]
                );
            }

            return $result->setData(['success' => true, 'message' => 'Connection successful!']);

        } catch (Throwable $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
