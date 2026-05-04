<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\Webhooks;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\OrderRepository;
use Psr\Log\LoggerInterface;
use Tradeaze\ApiIntegration\Api\Data\DataInterface;
use Tradeaze\ApiIntegration\Helper\Config;

class OrderManagementAbstract
{
    /**
     * @param Config $tradeazeConfig
     * @param OrderFactory $orderFactory
     * @param OrderRepository $orderRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        protected readonly Config $tradeazeConfig,
        protected readonly OrderFactory $orderFactory,
        protected readonly OrderRepository $orderRepository,
        protected readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Process the payload
     *
     * @param DataInterface $param
     * @return void
     * @throws WebapiException
     */
    protected function execute(DataInterface $param): void
    {
        try {
            $this->validatePayload($param);
            $this->updateOrder($param);
        } catch (\Exception $e) {
            $this->logger->error('Tradeaze webhook error: ' . $e->getMessage());
            throw new WebapiException(__('Webhook processing failed'), 0, WebapiException::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * Validate the payload
     *
     * @param DataInterface $param
     * @return void
     * @throws ValidatorException
     */
    protected function validatePayload(DataInterface $param): void
    {
        if (!$param->getId()) {
            throw new ValidatorException(__("Missing parameter 'id'"));
        }

        if (!$param->getExternalId()) {
            throw new ValidatorException(__("Missing parameter 'externalId'"));
        }

        if (!$param->getOrderStatus()) {
            throw new ValidatorException(__("Missing parameter 'orderStatus'"));
        }
    }

    /**
     * Update order according to data passed from Tradeaze
     *
     * @param DataInterface $param
     * @return void
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    protected function updateOrder(DataInterface $param): void
    {
        $order = $this->orderFactory->create()->loadByIncrementId($param->getExternalId());

        if (!$order->getId()) {
            throw new NoSuchEntityException(__("Order '%1' not found", $param->getExternalId()));
        }

        $order->setTradeazeOrderStatus($param->getOrderStatus());
        $order->addCommentToStatusHistory(
            __('Tradeaze order changed to status %1 via webhook', $param->getOrderStatus())
        );
        $this->orderRepository->save($order);
    }
}
