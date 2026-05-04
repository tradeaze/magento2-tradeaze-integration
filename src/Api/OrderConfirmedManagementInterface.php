<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Api;

use Magento\Framework\Exception\ValidatorException;
use Tradeaze\ApiIntegration\Api\Data\DataInterface;

interface OrderConfirmedManagementInterface
{
    /**
     * Create order with Tradeaze API
     *
     * @param DataInterface $data
     * @return void
     * @throws ValidatorException
     */
    public function postOrderConfirmed(
        DataInterface $data,
    ): void;
}
