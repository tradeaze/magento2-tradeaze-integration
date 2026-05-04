<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Api;

use Magento\Framework\Exception\ValidatorException;
use Tradeaze\ApiIntegration\Api\Data\DataInterface;

interface OrderUpdatedManagementInterface
{
    /**
     * Receives webhook from Tradeaze when an order is updated
     *
     * @param DataInterface $data
     * @return void
     * @throws ValidatorException
     */
    public function postOrderUpdated(
        DataInterface $data,
    ): void;
}
