<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Model\ResourceModel\Db;

use Magento\Framework\EntityManager\EntityMetadata;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Class ReadEntityRow
 */
class UpdateEntityRow
{
    /**
     * @var MetadataPool
     */
    protected $metadataPool;

    /**
     * @param MetadataPool $metadataPool
     */
    public function __construct(
        MetadataPool $metadataPool
    ) {
        $this->metadataPool = $metadataPool;
    }

    /**
     * Prepare data.
     *
     * @param EntityMetadata $metadata
     * @param array $data
     * @return array
     */
    protected function prepareData(EntityMetadata $metadata, $data)
    {
        $output = [];
        foreach ($metadata->getEntityConnection()->describeTable($metadata->getEntityTable()) as $column) {
            if ($column['DEFAULT'] == 'CURRENT_TIMESTAMP' || $column['IDENTITY']) {
                continue;
            }
            if (array_key_exists(strtolower($column['COLUMN_NAME']), $data)) {
                $output[strtolower($column['COLUMN_NAME'])] = $data[strtolower($column['COLUMN_NAME'])];
            }
        }
        return $output;
    }

    /**
     * Read entity row.
     *
     * @param string $entityType
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function execute($entityType, $data)
    {
        $metadata = $this->metadataPool->getMetadata($entityType);
        $connection = $metadata->getEntityConnection();
        return $connection->update(
            $metadata->getEntityTable(),
            $this->prepareData($metadata, $data),
            [$metadata->getLinkField() . ' = ?' => $data[$metadata->getLinkField()]]
        );
    }
}
