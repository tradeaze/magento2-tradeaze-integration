<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

class ValidateGeoNames extends Value
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param ResourceConnection $resourceConnection
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly ResourceConnection $resourceConnection,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Validate that GB GeoNames data exists before enabling
     *
     * @return static
     * @throws LocalizedException
     */
    public function beforeSave(): static
    {
        if ($this->getValue() === '1') {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('inventory_geoname');

            $select = $connection->select()
                ->from($tableName, ['COUNT(*)'])
                ->where('country_code = ?', 'GB');

            if (!$connection->isTableExists($tableName)
                || (int) $connection->fetchOne($select) === 0
            ) {
                throw new LocalizedException(
                    __(
                        'Tradeaze requires GeoNames data to be imported before enabling. '
                        . 'Please run "bin/magento inventory-geonames:import GB" from the CLI first.'
                    )
                );
            }
        }

        return parent::beforeSave();
    }
}
