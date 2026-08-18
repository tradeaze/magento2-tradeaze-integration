<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Observer\Sales;

use Exception;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterfaceFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use Psr\Log\LoggerInterface;
use Tradeaze\ApiIntegration\Api\TradeazeEndpoints\Delivery\CreateDeliveryInterface;
use Tradeaze\ApiIntegration\Helper\Config;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery\DeliveryStrategyResolver;
use Tradeaze\ApiIntegration\Service\InventorySourceValidator;
use Tradeaze\ApiIntegration\Service\OrderPaymentStatus;
use Tradeaze\ApiIntegration\Service\Tradeaze;

class OrderPlaceAfter implements ObserverInterface
{
    /** @var CreateDeliveryInterface */
    protected CreateDeliveryInterface $createDelivery;

    /**
     * @param DeliveryStrategyResolver $deliveryStrategyResolver
     * @param Config $tradeazeConfig
     * @param OrderRepository $orderRepository
     * @param LoggerInterface $logger
     * @param InventorySourceValidator $inventorySourceValidator
     * @param AddressInterfaceFactory $addressFactory
     * @param OrderPaymentStatus $orderPaymentStatus
     */
    public function __construct(
        protected readonly DeliveryStrategyResolver $deliveryStrategyResolver,
        protected readonly Config $tradeazeConfig,
        protected readonly OrderRepository $orderRepository,
        protected readonly LoggerInterface $logger,
        protected readonly InventorySourceValidator $inventorySourceValidator,
        protected readonly AddressInterfaceFactory $addressFactory,
        protected readonly OrderPaymentStatus $orderPaymentStatus,
    ) {
        $this->createDelivery = $this->deliveryStrategyResolver->resolve();
    }

    /**
     * Creates a Tradeaze delivery after order placement
     *
     * Only orders whose payment has already been captured in full are sent here - which
     * covers capture-on-order gateways, because Payment::place() runs the capture (and so
     * Invoice::pay()) before Order::place() dispatches this event. Everything else is parked
     * for the retry cron to pick up once the money lands, and failures are flagged for
     * retry too.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if (!$this->tradeazeConfig->isEnabled()) {
            return;
        }

        /** @var Order $order */
        $order = $observer->getEvent()->getData('order');

        // Gate on the shipping method first - only Tradeaze orders may be flagged for the
        // retry cron. Cast because shipping_method is null on virtual/downloadable orders.
        if (!str_contains((string) $order->getShippingMethod(), 'tradeaze')) {
            return;
        }

        $state = (string) $order->getState();

        // Terminal at placement - this order will never ship, so there is nothing to defer
        if (in_array($state, [Order::STATE_CANCELED, Order::STATE_CLOSED, Order::STATE_COMPLETE], true)) {
            return;
        }

        // Nothing is sent to Tradeaze until the order has been paid for in full. Whether that
        // is already true at placement depends on the provider: a capture-on-order gateway has
        // captured by now, while an authorize-only or redirect/offsite provider has not (and may
        // sit in any state of its own choosing - see OrderPaymentStatus for why the state is not
        // a usable signal). Park it so the retry cron creates the delivery once the money lands.
        //
        // Deliberately no orderRepository->save() here - Order::place() dispatches this
        // event as its last statement and the placement flow saves immediately afterwards,
        // which persists both the data and the status history comment.
        if (!$this->orderPaymentStatus->isPaidInFull($order)) {
            $this->applyResolvedSourceCode($order);
            $order->setTradeazeOrderStatus(Tradeaze::AWAITING_PAYMENT_STATUS);
            $order->addCommentToStatusHistory(__(
                'Tradeaze delivery deferred: order %1 has not been paid in full yet '
                . '(state "%2", %3 of %4 captured). The delivery will be created by the '
                . 'Tradeaze retry cron once the payment is captured.',
                $order->getIncrementId(),
                $state,
                (float) $order->getBaseTotalPaid(),
                (float) $order->getBaseGrandTotal()
            ));

            return;
        }

        try {
            $response = $this->createDelivery->execute(
                [
                    'request' => $order,
                ]
            );
            $order->setTradeazeOrderId($response['id']);
            $order->setTradeazeOrderStatus('PENDING');

            // Save the resolved source code for cron retry use
            $this->applyResolvedSourceCode($order);

            $order->addCommentToStatusHistory(
                __('Tradeaze delivery created successfully. Order Id %1', $response['id'])
            );
            $this->orderRepository->save($order);
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());

            // Still save the source code even on failure, for cron retry
            $this->applyResolvedSourceCode($order);

            $order->setTradeazeOrderStatus(Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY . '1');
            $order->addCommentToStatusHistory(
                __('Tradeaze delivery failed. Error was %1', $e->getMessage())
            );
            $this->orderRepository->save($order);
        }
    }

    /**
     * Resolve the source code for the order and store it for later cron retry use
     *
     * @param Order $order
     * @return void
     */
    private function applyResolvedSourceCode(Order $order): void
    {
        $sourceCode = $this->resolveSourceCodeForOrder($order);
        if ($sourceCode) {
            $order->setData('tradeaze_source_code', $sourceCode);
        }
    }

    /**
     * Resolve the source code for the order by re-running source resolution
     *
     * @param Order $order
     * @return string|null
     */
    private function resolveSourceCodeForOrder(Order $order): ?string
    {
        try {
            $items = [];
            foreach ($order->getAllItems() as $item) {
                if ($item->getProduct() && $item->getProduct()->getTypeId() === 'simple') {
                    $items[] = [
                        'sku' => $item->getSku(),
                        'qty' => (float) $item->getQtyOrdered()
                    ];
                }
            }

            if (empty($items)) {
                return null;
            }

            $addressDto = null;
            $shippingAddress = $order->getShippingAddress();
            if ($shippingAddress && $shippingAddress->getPostcode()) {
                $street = is_array($shippingAddress->getStreet())
                    ? implode(' ', $shippingAddress->getStreet())
                    : (string) $shippingAddress->getStreet();

                $addressDto = $this->addressFactory->create([
                    'country' => (string) $shippingAddress->getCountryId(),
                    'postcode' => (string) $shippingAddress->getPostcode(),
                    'street' => $street,
                    'region' => (string) $shippingAddress->getRegion(),
                    'city' => (string) $shippingAddress->getCity()
                ]);
            }

            $source = $this->inventorySourceValidator->resolveSource($items, $addressDto);
            return $source?->getSourceCode();
        } catch (Exception $e) {
            $this->logger->error("Failed to resolve source code for order - {$e->getMessage()}");
            return null;
        }
    }
}
