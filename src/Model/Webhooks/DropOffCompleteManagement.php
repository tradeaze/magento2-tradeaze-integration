<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\Webhooks;

use Tradeaze\ApiIntegration\Api\Data\DataInterface;
use Tradeaze\ApiIntegration\Api\DropOffCompleteManagementInterface;

class DropOffCompleteManagement extends OrderManagementAbstract implements DropOffCompleteManagementInterface
{
    /**
     * @inheritdoc
     */
    public function postDropOffComplete(
        DataInterface $data,
    ): void {
        $this->execute($data);
    }
}
