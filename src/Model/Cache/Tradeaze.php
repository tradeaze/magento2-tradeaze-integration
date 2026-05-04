<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\Cache;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

class Tradeaze extends TagScope
{

    /** @var string */
    public const TYPE_IDENTIFIER = 'tradeaze';
    /** @var string */
    public const CACHE_TAG = 'TRADEAZE';
    /** @var int */
    protected const CACHE_TIMEOUT = 300;

    /**
     * @param FrontendPool $cacheFrontendPool
     */
    public function __construct(
        FrontendPool $cacheFrontendPool
    ) {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }

    /**
     * Get cached response if available
     *
     * @param string $body
     * @return bool|array
     */
    public function loadResponseFromCache(string $body): bool|array
    {
        $response = $this->load($this->getIdentifier($body));
        return $response ? json_decode($response, true) : false;
    }

    /**
     * Set response to cache for future use
     *
     * @param string $body
     * @param array $response
     * @return void
     */
    public function saveResponseToCache(string $body, array $response): void
    {
        $this->save(
            json_encode($response),
            $this->getIdentifier($body),
            [],
            self::CACHE_TIMEOUT
        );
    }

    /**
     * Get the unique identifier based on the sha256 of the request body as string
     *
     * @param string $body
     * @return string
     */
    protected function getIdentifier(string $body): string
    {
        return hash('sha256', $body);
    }
}
