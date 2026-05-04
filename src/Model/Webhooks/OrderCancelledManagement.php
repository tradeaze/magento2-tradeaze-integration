<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\Webhooks;

use Tradeaze\ApiIntegration\Api\Data\DataInterface;
use Tradeaze\ApiIntegration\Api\OrderCancelledManagementInterface;

class OrderCancelledManagement extends OrderManagementAbstract implements OrderCancelledManagementInterface
{
    /**
     * @inheritdoc
     */
    public function postOrderCancelled(
        DataInterface $data,
    ): void {
        $this->execute($data);
    }
}
