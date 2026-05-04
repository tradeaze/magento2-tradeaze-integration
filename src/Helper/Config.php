<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;

class Config extends AbstractHelper
{
    protected const GROUP_GENERAL = 'general';
    protected const GROUP_PRODUCT_ATTRIBUTES = 'product_attributes';
    protected const GROUP_DELIVERY = 'delivery';
    protected const GROUP_MESSAGING = 'messaging';

    protected const ENABLED = 'enabled';
    protected const API_TOKEN = 'api_token';
    protected const API_MODE = 'api_mode';
    protected const CREATE_DRAFT_ORDERS = 'create_draft_orders';
    protected const TRADEAZE_LENGTH_ATTRIBUTE = 'tradeaze_length_attribute';
    protected const TRADEAZE_WIDTH_ATTRIBUTE = 'tradeaze_width_attribute';
    protected const TRADEAZE_HEIGHT_ATTRIBUTE = 'tradeaze_height_attribute';
    protected const TRADEAZE_WEIGHT_ATTRIBUTE = 'tradeaze_weight_attribute';
    protected const TRADEAZE_DEFAULT_UNIT = 'tradeaze_default_unit';
    protected const DELIVERY_CUTOFF_TIME_BUFFER = 'delivery_cutoff_time_buffer';
    protected const PDP_MESSAGING_ENABLED = 'pdp_messaging_enabled';
    protected const BASKET_MESSAGING_ENABLED = 'basket_messaging_enabled';
    protected const TRADEAZE_API_URL = 'https://api.tradeaze.app';
    protected const TRADEAZE_STAGE_API_URL = 'https://stage-api.tradeaze.app';

    /**
     * Check if the functionality is enabled in configuration
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->getConfigValue(self::GROUP_GENERAL, self::ENABLED) && $this->getAPIToken();
    }

    /**
     * Get the Tradeaze API Token
     *
     * @return string
     */
    public function getAPIToken(): string
    {
        return $this->getConfigValue(self::GROUP_GENERAL, self::API_TOKEN) ?: '';
    }

    /**
     * Get the Tradeaze API URL
     *
     * @return string
     */
    public function getApiUrl(): string
    {
        $mode = $this->getConfigValue(self::GROUP_GENERAL, self::API_MODE);

        return $mode === 'live' ? self::TRADEAZE_API_URL : self::TRADEAZE_STAGE_API_URL;
    }

    /**
     * Get the Tradeaze Length Attribute Code
     *
     * @return string
     */
    public function getTradeazeLengthAttribute(): string
    {
        return $this->getConfigValue(self::GROUP_PRODUCT_ATTRIBUTES, self::TRADEAZE_LENGTH_ATTRIBUTE) ?: '';
    }

    /**
     * Get the Tradeaze Width Attribute Code
     *
     * @return string
     */
    public function getTradeazeWidthAttribute(): string
    {
        return $this->getConfigValue(self::GROUP_PRODUCT_ATTRIBUTES, self::TRADEAZE_WIDTH_ATTRIBUTE) ?: '';
    }

    /**
     * Get the Tradeaze Height Attribute Code
     *
     * @return string
     */
    public function getTradeazeHeightAttribute(): string
    {
        return $this->getConfigValue(self::GROUP_PRODUCT_ATTRIBUTES, self::TRADEAZE_HEIGHT_ATTRIBUTE) ?: '';
    }

    /**
     * Get the Tradeaze Weight Attribute Code
     *
     * @return string
     */
    public function getTradeazeWeightAttribute(): string
    {
        return $this->getConfigValue(self::GROUP_PRODUCT_ATTRIBUTES, self::TRADEAZE_WEIGHT_ATTRIBUTE) ?: '';
    }

    /**
     * Get the Tradeaze Attribute Default Unit
     *
     * @return string
     */
    public function getTradeazeAttributeDefaultUnit(): string
    {
        return $this->getConfigValue(self::GROUP_PRODUCT_ATTRIBUTES, self::TRADEAZE_DEFAULT_UNIT) ?: '';
    }

    /**
     * Check if the product attribute mapping is set for all attributes
     *
     * @return array|bool
     */
    public function getProductAttributesToCheck(): bool|array
    {
        $attributes = array_filter([
            'length' => $this->getTradeazeLengthAttribute(),
            'width' => $this->getTradeazeWidthAttribute(),
            'height' => $this->getTradeazeHeightAttribute(),
            'weight' => $this->getTradeazeWeightAttribute()
        ]);

        return count($attributes) === 4 ? $attributes : false;
    }

    /**
     * Check if Draft Orders can be created
     *
     * @return bool
     */
    public function canCreateDraftOrders(): bool
    {
        return (bool) $this->getConfigValue(self::GROUP_GENERAL, self::CREATE_DRAFT_ORDERS);
    }

    /**
     * Check if PDP messaging is enabled
     *
     * @return bool
     */
    public function isPDPMessagingEnabled(): bool
    {
        return (bool) $this->getConfigValue(self::GROUP_MESSAGING, self::PDP_MESSAGING_ENABLED);
    }

    /**
     * Check if Basket messaging is enabled
     *
     * @return bool
     */
    public function isBasketMessagingEnabled(): bool
    {
        return (bool) $this->getConfigValue(self::GROUP_MESSAGING, self::BASKET_MESSAGING_ENABLED);
    }

    /**
     * Get Delivery Cutoff Time Buffer
     *
     * @return string
     */
    public function getDeliveryCutoffTimeBuffer(): string
    {
        return $this->getConfigValue(self::GROUP_DELIVERY, self::DELIVERY_CUTOFF_TIME_BUFFER);
    }

    /**
     * Get any scope config value by full path
     *
     * @param string $path
     * @param string $scopeType
     * @return string
     */
    public function getScopeConfigValue(string $path, string $scopeType = 'store'): string
    {
        return (string) $this->scopeConfig->getValue($path, $scopeType) ?: '';
    }

    /**
     * Get config value for a field within the tradeaze_api section
     *
     * @param string $group
     * @param string $fieldId
     * @param string $scopeType
     * @return string
     */
    protected function getConfigValue(string $group, string $fieldId, string $scopeType = 'store'): string
    {
        return $this->getScopeConfigValue("tradeaze_api/{$group}/{$fieldId}", $scopeType);
    }
}
