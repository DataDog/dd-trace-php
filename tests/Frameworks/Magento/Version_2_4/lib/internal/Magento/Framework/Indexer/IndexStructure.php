<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Indexer\ScopeResolver\FlatScopeResolver;
use Magento\Framework\Indexer\ScopeResolver\IndexScopeResolver;
use Magento\Framework\Search\Request\Dimension;

/**
 * Full text search index structure.
 *
 * @deprecated 102.0.0
 * @see \Magento\ElasticSearch
 */
class IndexStructure implements IndexStructureInterface
{
    /**
     * @var Resource
     */
    private $resource;

    /**
     * @var \Magento\Framework\Indexer\ScopeResolver\IndexScopeResolver
     */
    private $indexScopeResolver;

    /**
     * @var FlatScopeResolver
     */
    private $flatScopeResolver;

    /**
     * @var array
     */
    protected $columnTypesMap = [
        'varchar'    => ['type' => Table::TYPE_TEXT, 'size' => 255],
        'mediumtext' => ['type' => Table::TYPE_TEXT, 'size' => 16777216],
        'text'       => ['type' => Table::TYPE_TEXT, 'size' => 65536],
    ];

    /**
     * @param ResourceConnection $resource
     * @param IndexScopeResolver $indexScopeResolver
     * @param \Magento\Framework\Indexer\ScopeResolver\FlatScopeResolver $flatScopeResolver
     * @param array $columnTypesMap
     */
    public function __construct(
        ResourceConnection $resource,
        IndexScopeResolver $indexScopeResolver,
        FlatScopeResolver $flatScopeResolver,
        array $columnTypesMap = []
    ) {
        $this->resource = $resource;
        $this->indexScopeResolver = $indexScopeResolver;
        $this->flatScopeResolver = $flatScopeResolver;
        $this->columnTypesMap = array_merge($this->columnTypesMap, $columnTypesMap);
    }

    /**
     * @inheritdoc
     */
    public function delete($index, array $dimensions = [])
    {
        $this->dropTable($this->resource->getConnection(), $this->indexScopeResolver->resolve($index, $dimensions));
        $this->dropTable($this->resource->getConnection(), $this->flatScopeResolver->resolve($index, $dimensions));
    }

    /**
     * @inheritdoc
     */
    public function create($index, array $fields, array $dimensions = [])
    {
        $this->createFulltextIndex($this->indexScopeResolver->resolve($index, $dimensions));
        if ($fields) {
            $this->createFlatIndex($this->flatScopeResolver->resolve($index, $dimensions), $fields);
        }
    }

    /**
     * Create full text index.
     *
     * @param string $tableName
     * @throws \Zend_Db_Exception
     * @return void
     */
    protected function createFulltextIndex($tableName)
    {
        $table = $this->configureFulltextTable($this->resource->getConnection()->newTable($tableName));
        $this->resource->getConnection()->createTable($table);
    }

    /**
     * Configure full text index table.
     *
     * @param Table $table
     * @return Table
     */
    protected function configureFulltextTable(Table $table)
    {
        $table->addColumn(
            'entity_id',
            Table::TYPE_INTEGER,
            10,
            ['unsigned' => true, 'nullable' => false],
            'Entity ID'
        )->addColumn(
            'attribute_id',
            Table::TYPE_TEXT,
            255,
            ['unsigned' => true, 'nullable' => true]
        )->addColumn(
            'data_index',
            Table::TYPE_TEXT,
            '4g',
            ['nullable' => true],
            'Data index'
        )->addIndex(
            'idx_primary',
            ['entity_id', 'attribute_id'],
            ['type' => AdapterInterface::INDEX_TYPE_PRIMARY]
        )->addIndex(
            'FTI_FULLTEXT_DATA_INDEX',
            ['data_index'],
            ['type' => AdapterInterface::INDEX_TYPE_FULLTEXT]
        );
        return $table;
    }

    /**
     * Create flat index.
     *
     * @param string $tableName
     * @param array $fields
     * @throws \Zend_Db_Exception
     * @return void
     */
    protected function createFlatIndex($tableName, array $fields)
    {
        $table = $this->resource->getConnection()->newTable($tableName);
        $table->addColumn(
            'entity_id',
            Table::TYPE_INTEGER,
            10,
            ['unsigned' => true, 'nullable' => false],
            'Entity ID'
        );
        foreach ($fields as $field) {
            if ($field['type'] !== 'filterable') {
                continue;
            }
            $columnMap = isset($field['dataType']) && isset($this->columnTypesMap[$field['dataType']])
                ? $this->columnTypesMap[$field['dataType']]
                : ['type' => $field['type'], 'size' => isset($field['size']) ? $field['size'] : null];
            $name = $field['name'];
            $type = $columnMap['type'];
            $size = $columnMap['size'];
            $table->addColumn($name, $type, $size);
        }
        $this->resource->getConnection()->createTable($table);
    }

    /**
     * Drop table.
     *
     * @param AdapterInterface $connection
     * @param string $tableName
     * @return void
     */
    private function dropTable(AdapterInterface $connection, $tableName)
    {
        if ($connection->isTableExists($tableName)) {
            $connection->dropTable($tableName);
        }
    }
}
