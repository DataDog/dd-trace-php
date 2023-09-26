<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Search\Adapter\Mysql;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\DB\Select;

/**
 * MySQL search temporary storage.
 *
 * @api
 * @deprecated 102.0.0
 * @see \Magento\ElasticSearch
 * @since 100.0.2
 */
class TemporaryStorage
{
    const TEMPORARY_TABLE_PREFIX = 'search_tmp_';

    const FIELD_ENTITY_ID = 'entity_id';
    const FIELD_SCORE = 'score';

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private $resource;

    /**
     * @var DeploymentConfig
     */
    private $config;

    /**
     * @param \Magento\Framework\App\ResourceConnection $resource
     * @param DeploymentConfig|null $config
     */
    public function __construct(
        \Magento\Framework\App\ResourceConnection $resource,
        DeploymentConfig $config = null
    ) {
        $this->resource = $resource;
        $this->config = $config !== null ? $config : ObjectManager::getInstance()->get(DeploymentConfig::class);
    }

    /**
     * Stores Documents
     *
     * @param \Magento\Framework\Api\Search\DocumentInterface[] $documents
     * @return Table
     * @deprecated 100.1.0
     */
    public function storeDocuments($documents)
    {
        return $this->storeApiDocuments($documents);
    }

    /**
     * Stores Api type Documents
     *
     * @param \Magento\Framework\Api\Search\DocumentInterface[] $documents
     * @return Table
     * @since 100.1.0
     */
    public function storeApiDocuments($documents)
    {
        $data = [];
        foreach ($documents as $document) {
            $data[] = [
                $document->getId(),
                $document->getCustomAttribute('score')->getValue(),
            ];
        }

        return $this->populateTemporaryTable($this->createTemporaryTable(), $data);
    }

    /**
     * Populates temporary table
     *
     * @param Table $table
     * @param array $data
     * @return Table
     * @throws \Zend_Db_Exception
     */
    private function populateTemporaryTable(Table $table, $data)
    {
        if (count($data)) {
            $this->getConnection()->insertArray(
                $table->getName(),
                [
                    self::FIELD_ENTITY_ID,
                    self::FIELD_SCORE,
                ],
                $data
            );
        }
        return $table;
    }

    /**
     * Store select results in temporary table.
     *
     * @param Select $select
     * @return Table
     * @throws \Zend_Db_Exception
     */
    public function storeDocumentsFromSelect(Select $select)
    {
        $table = $this->createTemporaryTable();
        $this->getConnection()->query($this->getConnection()->insertFromSelect($select, $table->getName()));
        return $table;
    }

    /**
     * Get connection.
     *
     * @return false|AdapterInterface
     */
    private function getConnection()
    {
        return $this->resource->getConnection();
    }

    /**
     * Create temporary table for search select results.
     *
     * @return Table
     * @throws \Zend_Db_Exception
     */
    private function createTemporaryTable()
    {
        $connection = $this->getConnection();
        $tableName = $this->resource->getTableName(str_replace('.', '_', uniqid(self::TEMPORARY_TABLE_PREFIX, true)));
        $table = $connection->newTable($tableName);
        if ($this->config->get('db/connection/indexer/persistent')) {
            $connection->dropTemporaryTable($table->getName());
        }
        $table->addColumn(
            self::FIELD_ENTITY_ID,
            Table::TYPE_INTEGER,
            10,
            ['unsigned' => true, 'nullable' => false, 'primary' => true],
            'Entity ID'
        );
        $table->addColumn(
            self::FIELD_SCORE,
            Table::TYPE_DECIMAL,
            [32, 16],
            ['unsigned' => true, 'nullable' => true],
            'Score'
        );
        $table->setOption('type', 'memory');
        $connection->createTemporaryTable($table);
        return $table;
    }
}
