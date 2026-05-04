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
     */
    public function __construct(
        protected readonly DeliveryStrategyResolver $deliveryStrategyResolver,
        protected readonly Config $tradeazeConfig,
        protected readonly OrderRepository $orderRepository,
        protected readonly LoggerInterface $logger,
        protected readonly InventorySourceValidator $inventorySourceValidator,
        protected readonly AddressInterfaceFactory $addressFactory,
    ) {
        $this->createDelivery = $this->deliveryStrategyResolver->resolve();
    }

    /**
     * Creates a Tradeaze delivery after order placement, or marks it for cron retry on failure
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if ($this->tradeazeConfig->isEnabled()) {

            /** @var Order $order */
            $order = $observer->getEvent()->getData('order');

            if (!in_array($order->getStatus(), ['pending', 'processing'])) {
                return;
            }

            if (!str_contains($order->getShippingMethod(), 'tradeaze')) {
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
                $sourceCode = $this->resolveSourceCodeForOrder($order);
                if ($sourceCode) {
                    $order->setData('tradeaze_source_code', $sourceCode);
                }

                $order->addCommentToStatusHistory(
                    __('Tradeaze delivery created successfully. Order Id %1', $response['id'])
                );
                $this->orderRepository->save($order);
            } catch (Exception $e) {
                $this->logger->error($e->getMessage());

                // Still save the source code even on failure, for cron retry
                $sourceCode = $this->resolveSourceCodeForOrder($order);
                if ($sourceCode) {
                    $order->setData('tradeaze_source_code', $sourceCode);
                }

                $order->setTradeazeOrderStatus(Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY . '1');
                $order->addCommentToStatusHistory(
                    __('Tradeaze delivery failed. Error was %1', $e->getMessage())
                );
                $this->orderRepository->save($order);
            }
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
