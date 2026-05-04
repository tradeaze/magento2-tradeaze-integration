<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Controller\Adminhtml\Cancel;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use Psr\Log\LoggerInterface;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery\CancelDelivery;

class Index extends Action implements HttpGetActionInterface
{
    /**
     * Authorization level of a basic admin session
     */
    public const ADMIN_RESOURCE = 'Tradeaze_ApiIntegration::orderupdate';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param Http $request
     * @param OrderRepository $orderRepository
     * @param CancelDelivery $cancelDelivery
     * @param LoggerInterface $logger
     */
    public function __construct(
        protected readonly Context $context,
        protected readonly PageFactory $resultPageFactory,
        protected readonly Http $request,
        protected readonly OrderRepository $orderRepository,
        protected readonly CancelDelivery $cancelDelivery,
        protected readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    public function execute(): ResultInterface
    {
        $orderId = $this->request->getParam('order_id');

        try {
            /** @var Order $order */
            $order = $this->orderRepository->get($orderId);

            if ($order->getTradeazeOrderId()) {
                $this->cancelDelivery->execute(
                    [
                        'request' => $order,
                    ]
                );
                $this->messageManager->addSuccessMessage(
                    __('The order was cancelled on Tradeaze via Magento Admin')
                );
                $order->addCommentToStatusHistory(
                    __('The order was cancelled on Tradeaze via Magento Admin')
                );
                $order->setTradeazeOrderStatus('CANCELLED');
                $this->orderRepository->save($order);
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $this->messageManager->addErrorMessage(
                __('An error has happened during the cancellation. See exception log for details.')
            );
        }

        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);

        return $resultRedirect;
    }
}
