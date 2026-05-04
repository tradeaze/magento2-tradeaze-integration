<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Quote;

use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Validator\Exception as ValidatorException;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Quote\Model\Quote\Item;
use Tradeaze\ApiIntegration\Api\TradeazeEndpoints\Quote\GetDeliveryQuoteInterface;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\ClientAbstract;

class GetDeliveryQuoteWithoutPickup extends ClientAbstract implements GetDeliveryQuoteInterface
{
    /**
     * @inheritdoc
     */
    public function buildRequest(): array
    {
        /** @var CartInterface $requestObject */
        $requestObject = $this->params['request'];

        $request = [
            'dropoff' => [
                'postcode' => $requestObject->getShippingAddress()->getPostcode(),
            ],
            'includeNextWorkingDay' => true
//            'startTime' => 'string',
        ];

        /** @var Item $item */
        foreach ($requestObject->getAllItems() as $item) {
            $product = $item->getProduct();

            if ($product && $product->getTypeId() === 'simple') {
                $itemData = $this->tradeaze->getSizeAndWeightMapping($product);
                $itemData['name'] = $item->getName();
                $itemData['quantity'] = $item->getQty();
                $request['items'][] = $itemData;
            }
        }

        return $request;
    }

    /**
     * @inheritdoc
     */
    public function parseResponse(mixed $response): array
    {
        $responseObject = parent::parseResponse($response);
        $methods = [];

        if (isset($responseObject['cheapestAvailableVehicleOptions'])) {
            foreach ($responseObject['cheapestAvailableVehicleOptions'] as $methodData) {
                $now = $this->timezone->date(null, null, false);
                if ($cutoffTimeBuffer = $this->tradeazeConfig->getDeliveryCutoffTimeBuffer()) {
                    try {
                        $now->modify('+' . $cutoffTimeBuffer . ' minutes');
                    } catch (Exception $e) {
                        $this->logger->error($e->getMessage());
                    }
                }

                $cutOffTime = $this->timezone->date($methodData['cutOffTime']['timestamp'], null, false);

                if ($methodData['isAvailable']
                    && (($now < $cutOffTime) || $methodData['deliveryDate'] != $now->format('Y-m-d'))
                ) {

                    // e.g. "2026-02-07T08:04:00.000Z"
                    $windowStart = $this->timezone->date($methodData['windowStart']);
                    $dataSuffix =
                        $windowStart->format('Y-m-d') === $this->timezone->date()->format('Y-m-d')
                        ? '_TODAY'
                        : '_TOMORROW';

                    // Add 10 minutes to avoid rejections and get HHMM
                    $timePart = $windowStart->modify('+10 minutes')->format('Hi');

                    $dataSuffix .= $timePart;

                    $methodPrice = $methodData['deliveryPrice']['amount'] + $methodData['serviceCharge']['amount'];
                    $methods[] = [
                        'methodCode' => $methodData['id'] . $dataSuffix,
                        'methodTitle' => $methodData['displayName'],
                        'methodPrice' => $methodPrice,
                        'methodCost' => $methodPrice,
                        'methodDeliveryDate' => $methodData['deliveryDate'],
                        'methodWindowStart' => $methodData['windowStart'],
                    ];
                }
            }
        }

        return $methods;
    }

    /**
     * @inheritdoc
     */
    protected function validateParams(): void
    {
        if (! isset($this->params['request'])) {
            throw new ValidatorException(__('Missing required param "request"'));
        }

        if (! $this->params['request'] instanceof CartInterface) {
            throw new ValidatorException(
                __('Parameter "request" must be instance of \Magento\Quote\Api\Data\CartInterface')
            );
        }

        $request = $this->params['request'];

        if (!$shippingAddress = $request->getShippingAddress()) {
            throw new ValidatorException(
                __('No shipping address is set for this quote')
            );
        }

        if (!$shippingAddress->getPostcode()) {
            throw new LocalizedException(__('Missing required params drop-off postcode'));
        }

        if (!$this->canSendToTradeaze($request->getAllItems(), $shippingAddress)) {
            throw new LocalizedException(__('Cannot send request to Tradeaze'));
        }
    }

    /**
     * @inheritdoc
     */
    protected function setEndpointPath(): void
    {
        $this->endpointPath = '/v1/deliveries/quote/no-pickup';// @todo Tradeaze have not implemented this endpoint
    }

    /**
     * @inheritdoc
     */
    protected function setMethod(): void
    {
        $this->method = 'POST';
    }

    /**
     * Check if all the items in the current quote are eligible for Tradeaze shipping
     *
     * Resolves the closest inventory source that can fulfill all items and stores
     * it in $this->resolvedSource for use by buildRequest()
     *
     * @param Item[] $requestItems
     * @param QuoteAddress|null $shippingAddress
     * @return bool
     */
    protected function canSendToTradeaze(
        array $requestItems,
        ?QuoteAddress $shippingAddress = null
    ): bool {
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
                    'qty' => (float) $item->getQty()
                ];
            }
        }

        if (empty($items)) {
            return false;
        }

        try {
            // Build address DTO for geocoding if shipping address is available
            $addressDto = null;
            if ($shippingAddress && $shippingAddress->getPostcode()) {
                $addressDto = $this->buildAddressDto($shippingAddress);
            }

            $source = $this->inventorySourceValidator->resolveSource($items, $addressDto);

            if ($source === null) {
                return false;
            }

            $this->resolvedSource = $source;
        } catch (LocalizedException $e) {
            $this->logger->error("Error validating Tradeaze quote inventory sources - {$e->getMessage()}");
            return false;
        }

        return true;
    }

    /**
     * Build an AddressInterface DTO from a quote shipping address
     *
     * @param QuoteAddress $shippingAddress
     * @return AddressInterface
     */
    protected function buildAddressDto(QuoteAddress $shippingAddress): AddressInterface
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
