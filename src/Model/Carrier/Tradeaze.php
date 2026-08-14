<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\Carrier;

use Magento\Backend\Model\Session\Quote;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\IntegrationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\Validator\Exception as ValidatorException;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;
use Tradeaze\ApiIntegration\Api\TradeazeEndpoints\Quote\GetDeliveryQuoteInterface;
use Tradeaze\ApiIntegration\Helper\Config;
use Tradeaze\ApiIntegration\Model\DeliverySelection;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Quote\QuoteStrategyResolver;

class Tradeaze extends AbstractCarrier implements
    CarrierInterface
{
    /**
     * @var string
     */
    protected $_code = 'tradeaze';

    /**
     * @var bool
     */
    protected $_isFixed = true;

    /** @var GetDeliveryQuoteInterface */
    protected GetDeliveryQuoteInterface $getDeliveryQuote;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ErrorFactory $rateErrorFactory
     * @param LoggerInterface $logger
     * @param ResultFactory $rateResultFactory
     * @param MethodFactory $rateMethodFactory
     * @param QuoteStrategyResolver $quoteStrategyResolver
     * @param Config $tradeazeConfig
     * @param Session $frontendSession
     * @param Quote $backendQuoteSession
     * @param State $appState
     * @param TimezoneInterface $timezone
     * @param array $data
     */
    public function __construct(
        protected readonly ScopeConfigInterface $scopeConfig,
        protected readonly ErrorFactory $rateErrorFactory,
        protected readonly LoggerInterface $logger,
        protected readonly ResultFactory $rateResultFactory,
        protected readonly MethodFactory $rateMethodFactory,
        protected readonly QuoteStrategyResolver $quoteStrategyResolver,
        protected readonly Config $tradeazeConfig,
        protected readonly Session $frontendSession,
        protected readonly Quote $backendQuoteSession,
        protected readonly State $appState,
        protected readonly TimezoneInterface $timezone,
        protected readonly array $data = []
    ) {
        parent::__construct($this->scopeConfig, $this->rateErrorFactory, $this->logger, $this->data);
        $this->getDeliveryQuote = $this->quoteStrategyResolver->resolve();
    }

    /**
     * @inheritdoc
     */
    public function collectRates(RateRequest $request): DataObject|Result|bool|null
    {
        if (!$this->isTradeazeCarrierAvailable()) {
            return false;
        }

        try {
            if (!$quote = $this->getQuote()) {
                return false;
            }
            $methodDataList = $this->getDeliveryQuote->execute(
                [
                    'request' => $quote,
                    'use_cache' => true,
                ]
            );
        } catch (IntegrationException|ValidatorException|LocalizedException $e) {
            $this->logger->error($e->getMessage());
            return false;
        }

        if (empty($methodDataList)) {
            return false;
        }

        $result = $this->rateResultFactory->create();

        foreach ($methodDataList as $methodData) {
            $method = $this->rateMethodFactory->create();
            $method->setCarrier($this->_code);
            $method->setCarrierTitle($this->getConfigData('title'));

            $method->setMethod($methodData['methodCode']);
            $method->setMethodTitle($methodData['methodTitle']);
            $method->setPrice($methodData['methodPrice']);
            $method->setCost($methodData['methodCost']);

            foreach (DeliverySelection::PERSISTED_FIELDS as $field) {
                if (isset($methodData[$field])) {
                    $method->setData($field, $methodData[$field]);
                }
            }

            $result->append($method);
        }

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function getAllowedMethods(): array
    {
        return [$this->_code => $this->getConfigData('name')];
    }

    /**
     * Check if the Tradeaze Carrier can be added to the shipping method list
     *
     * @return bool
     */
    protected function isTradeazeCarrierAvailable(): bool
    {
        //True if:
        //- the Tradeaze carrier method is active
        return $this->getConfigFlag('active')
            //- the Tradeaze configuration is Enabled
            //- the Tradeaze API Token is set
            && $this->tradeazeConfig->isEnabled();
    }

    /**
     * Get the quote
     *
     * @return CartInterface|null
     */
    public function getQuote(): CartInterface|null
    {
        try {
            return match ($this->appState->getAreaCode()) {
                Area::AREA_FRONTEND, Area::AREA_WEBAPI_REST => $this->frontendSession->getQuote(),
                Area::AREA_ADMINHTML => $this->backendQuoteSession->getQuote(),
                default => null,
            };
        } catch (NoSuchEntityException|LocalizedException) {
            return null;
        }
    }
}
