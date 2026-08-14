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
use Tradeaze\ApiIntegration\Helper\Config;
use Tradeaze\ApiIntegration\Service\DeliverySynchronizer;
use Tradeaze\ApiIntegration\Service\InventorySourceValidator;
use Tradeaze\ApiIntegration\Service\Tradeaze;

class OrderPlaceAfter implements ObserverInterface
{
    /**
     * @param Config $tradeazeConfig
     * @param OrderRepository $orderRepository
     * @param LoggerInterface $logger
     * @param InventorySourceValidator $inventorySourceValidator
     * @param AddressInterfaceFactory $addressFactory
     * @param DeliverySynchronizer $deliverySynchronizer
     */
    public function __construct(
        protected readonly Config $tradeazeConfig,
        protected readonly OrderRepository $orderRepository,
        protected readonly LoggerInterface $logger,
        protected readonly InventorySourceValidator $inventorySourceValidator,
        protected readonly AddressInterfaceFactory $addressFactory,
        private readonly DeliverySynchronizer $deliverySynchronizer,
    ) {
    }

    /**
     * Creates a Tradeaze delivery after order placement, or marks it for cron retry on failure
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /** @var Order $order */
        $order = $observer->getEvent()->getData('order');

        if (!str_starts_with((string) $order->getShippingMethod(), 'tradeaze_')) {
            return;
        }

        if (!$this->tradeazeConfig->isEnabled()) {
            $this->logger->warning(
                'Tradeaze order was not synchronized because the integration is disabled',
                $this->getLogContext($order)
            );
            return;
        }

        $sourceCode = $this->resolveSourceCodeForOrder($order);
        if ($sourceCode) {
            $order->setData('tradeaze_source_code', $sourceCode);
        }

        if ($order->getState() !== Order::STATE_PROCESSING) {
            $order->setTradeazeOrderStatus(Tradeaze::AWAITING_PROCESSING_STATUS);

            $this->logger->info(
                'Tradeaze delivery deferred until the Magento order is processing',
                $this->getLogContext($order)
            );
            $this->orderRepository->save($order);
            return;
        }

        $this->deliverySynchronizer->synchronize($order, 'order_placement');
    }

    /**
     * Build safe structured context for Tradeaze order logs
     *
     * @param Order $order
     * @return array
     */
    private function getLogContext(Order $order): array
    {
        return [
            'order_id' => $order->getIncrementId(),
            'entity_id' => $order->getEntityId(),
            'state' => $order->getState(),
            'status' => $order->getStatus(),
            'shipping_method' => $order->getShippingMethod(),
            'store_id' => $order->getStoreId(),
        ];
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
