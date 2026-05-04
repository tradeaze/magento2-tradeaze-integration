<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Api\TradeazeEndpoints\Delivery;

use Magento\Framework\Exception\IntegrationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Validator\Exception as ValidatorException;

interface CreateDeliveryInterface
{
    /**
     * Build the request to be sent to Tradeaze
     *
     * @return array
     */
    public function buildRequest(): array;

    /**
     * Parse data from the Tradeaze response object
     *
     * @param mixed $response
     * @return array
     */
    public function parseResponse(mixed $response): array;

    /**
     * Execute the call to the Tradeaze API
     *
     * @param array $params
     * @return array
     * @throws IntegrationException
     * @throws LocalizedException
     * @throws ValidatorException
     */
    public function execute(array $params): array;
}
