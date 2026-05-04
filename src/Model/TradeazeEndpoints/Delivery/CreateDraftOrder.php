<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery;

use DateTimeZone;
use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Validator\Exception as ValidatorException;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\Item;
use Tradeaze\ApiIntegration\Api\TradeazeEndpoints\Delivery\CreateDeliveryInterface;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\ClientAbstract;

class CreateDraftOrder extends ClientAbstract implements CreateDeliveryInterface
{
    /**
     * @inheritdoc
     */
    public function buildRequest(): array
    {
        /** @var Order $requestObject */
        $requestObject = $this->params['request'];
        $shippingAddress = $requestObject->getShippingAddress();

        $method = $requestObject->getShippingMethod(); // e.g. "tradeaze_CAR_EVENING_TOMORROW0814"

        $matchResult = preg_match(
            '/^tradeaze_(.+)_(TODAY|TOMORROW)(\d{2})(\d{2})$/',
            $method,
            $methodData,
        );

        if ($matchResult !== 1) {
            throw new ValidatorException(
                __('Invalid Tradeaze shipping method format: %1', $method),
            );
        }

        $deliveryOptionCode = $methodData[1]; // CAR_EVENING
        $dayFlag = $methodData[2];            // TODAY / TOMORROW
        $hour = (int) $methodData[3];
        $minute = (int) $methodData[4];

        $date = $this->timezone->date();

        if ($dayFlag === 'TOMORROW') {
            try {
                $date->modify('+1 day');
            } catch (Exception $e) {
                $this->logger->error($e->getMessage());
            }
        }

        $date->setTime($hour, $minute, 0);

        /**
         * An ISO string date of the time the delivery is scheduled to start.
         * If not provided, defaults to the current time.
         *
         * Match pattern:
         * ^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$
         * Convert to UTC since regex ends with Z, which in ISO-8601 specifically means "UTC time"
         */
        $date->setTimezone(new DateTimeZone('UTC'));

        $startTime = $date->format('Y-m-d\TH:i:s\Z');

        $request = [
            'externalId' => $requestObject->getIncrementId(),
            'deliveryOptionId' => $deliveryOptionCode,
            'tips' => [
                'amount' => 0,//required
                'currency' => 'GBP'//required
            ],
            'dropoff' => [
                'addressLine1' => $shippingAddress->getStreetLine(1),
                'addressLine2' => $shippingAddress->getStreetLine(2),
                'addressLine3' => $shippingAddress->getStreetLine(3),
                'postCode' => $shippingAddress->getPostcode(),//required
                'city' => $shippingAddress->getCity(),
                'country' => $this->tradeaze->getCountryName($shippingAddress->getCountryId()),
//                'position' => [
//                    'latitude' => -90,
//                    'longitude' => -180
//                ],
//                'instructions' => 'string',
                'contactName' => $shippingAddress->getName(),
                'contactNumber' => $shippingAddress->getTelephone(),
                'companyName' => $shippingAddress->getCompany(),
                'collectionReference' => $requestObject->getIncrementId(),
                /*
                 * The "requiresSignature" and "requiresImage" fields in the drop-off section of the API request
                 * are always set to true.
                 */
                'requiresSignature' => true,//required
                'requiresImage' => true,//required
                'type' => 'DROP_OFF'//required
            ],
//            'metadata' => [],
            'startTime' => $startTime
        ];

        foreach ($requestObject->getAllItems() as $item) {
            $product = $item->getProduct();

            if ($product && $product->getTypeId() === 'simple') {
                $itemData = [
                    //A unique identifier for the item, such as the SKU or product code
                    'externalId' => $product->getSku(),
                    'name' => $product->getName(),
                    'quantity' => (float) $item->getQtyOrdered(),
                    'description' => null,
                    'value' => [
                        'amount' => $item->getPrice(),
                        'currency' => 'GBP'
                    ]
                ];
                // Get dimensions + weight mapping from Tradeaze
                $sizeWeightData = $this->tradeaze->getSizeAndWeightMapping($product);

                $itemData += $sizeWeightData;

                $request['items'][] = $itemData;
            }
        }

        return $request;
    }

    /**
     * @inheritdoc
     */
    protected function setEndpointPath(): void
    {
        $this->endpointPath = '/v1/deliveries/draft';//@todo Tradeaze have not implemented this endpoint
    }

    /**
     * @inheritdoc
     */
    protected function setMethod(): void
    {
        $this->method = 'POST';
    }

    /**
     * @inheritdoc
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

        if (!$this->canSendToTradeaze($order->getAllItems(), $order)) {
            throw new LocalizedException(__('Cannot send request to Tradeaze'));
        }
    }

    /**
     * Check if all the items in the current order are eligible for Tradeaze shipping
     *
     * Resolves the closest inventory source and stores it in $this->resolvedSource.
     * If an explicit source code is provided in params (e.g. from cron retry),
     * that source is loaded directly instead of re-resolving.
     *
     * @param Item[] $requestItems
     * @param Order|null $order
     * @return bool
     */
    protected function canSendToTradeaze(array $requestItems, ?Order $order = null): bool
    {
        // If a pre-resolved source is passed in params (e.g. cron retry), use it directly
        if (isset($this->params['resolved_source']) && $this->params['resolved_source'] instanceof SourceInterface) {
            $this->resolvedSource = $this->params['resolved_source'];
            return $this->validateProductEligibility($requestItems);
        }

        // Build items array for source resolution
        $items = [];
        foreach ($requestItems as $item) {
            $product = $item->getProduct();

            if ($product && $product->getTypeId() === 'simple') {
                if (!$this->tradeaze->isTradeazeProduct($product)) {
                    return false;
                }
                $items[] = [
                    'sku' => $item->getSku(),
                    'qty' => (float) $item->getQtyOrdered()
                ];
            }
        }

        if (empty($items)) {
            return false;
        }

        try {
            $addressDto = null;
            if ($order) {
                $shippingAddress = $order->getShippingAddress();
                if ($shippingAddress && $shippingAddress->getPostcode()) {
                    $addressDto = $this->buildAddressDtoFromOrder($shippingAddress);
                }
            }

            $source = $this->inventorySourceValidator->resolveSource($items, $addressDto);

            if ($source === null) {
                return false;
            }

            $this->resolvedSource = $source;
        } catch (LocalizedException $e) {
            $this->logger->error("Error validating Tradeaze delivery inventory sources - {$e->getMessage()}");
            return false;
        }

        return true;
    }

    /**
     * Validate that all items are Tradeaze-eligible products
     *
     * @param Item[] $requestItems
     * @return bool
     */
    private function validateProductEligibility(array $requestItems): bool
    {
        foreach ($requestItems as $item) {
            $product = $item->getProduct();

            if ($product && $product->getTypeId() === 'simple' && !$this->tradeaze->isTradeazeProduct($product)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Build an AddressInterface DTO from an order shipping address
     *
     * @param OrderAddress $shippingAddress
     * @return AddressInterface
     */
    protected function buildAddressDtoFromOrder(OrderAddress $shippingAddress): AddressInterface
    {
        $street = is_array($shippingAddress->getStreet())
            ? implode(' ', $shippingAddress->getStreet())
            : (string) $shippingAddress->getStreet();

        return $this->addressFactory->create([
            'country' => (string) $shippingAddress->getCountryId(),
            'postcode' => (string) $shippingAddress->getPostcode(),
            'street' => $street,
            'region' => (string) $shippingAddress->getRegion(),
            'city' => (string) $shippingAddress->getCity()
        ]);
    }
}
