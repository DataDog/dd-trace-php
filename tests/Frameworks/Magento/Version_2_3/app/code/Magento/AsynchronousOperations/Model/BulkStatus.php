<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\AsynchronousOperations\Model;

use Magento\AsynchronousOperations\Api\Data\BulkSummaryInterface;
use Magento\AsynchronousOperations\Api\Data\BulkSummaryInterfaceFactory;
use Magento\AsynchronousOperations\Api\Data\OperationInterface;
use Magento\AsynchronousOperations\Api\Data\OperationInterfaceFactory;
use Magento\AsynchronousOperations\Model\BulkStatus\CalculatedStatusSql;
use Magento\AsynchronousOperations\Model\ResourceModel\Operation\Collection as OperationCollection;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Bulk\BulkStatusInterface;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Process bulk operations status.
 */
class BulkStatus implements BulkStatusInterface
{
    /**
     * @var BulkSummaryInterfaceFactory
     */
    private $bulkCollectionFactory;

    /**
     * @var OperationInterfaceFactory
     */
    private $operationCollectionFactory;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var CalculatedStatusSql
     */
    private $calculatedStatusSql;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * @param ResourceModel\Bulk\CollectionFactory $bulkCollection
     * @param ResourceModel\Operation\CollectionFactory $operationCollection
     * @param ResourceConnection $resourceConnection
     * @param CalculatedStatusSql $calculatedStatusSql
     * @param MetadataPool $metadataPool
     */
    public function __construct(
        ResourceModel\Bulk\CollectionFactory $bulkCollection,
        ResourceModel\Operation\CollectionFactory $operationCollection,
        ResourceConnection $resourceConnection,
        CalculatedStatusSql $calculatedStatusSql,
        MetadataPool $metadataPool
    ) {
        $this->bulkCollectionFactory = $bulkCollection;
        $this->operationCollectionFactory = $operationCollection;
        $this->resourceConnection = $resourceConnection;
        $this->calculatedStatusSql = $calculatedStatusSql;
        $this->metadataPool = $metadataPool;
    }

    /**
     * @inheritDoc
     */
    public function getFailedOperationsByBulkId($bulkUuid, $failureType = null)
    {
        $failureCodes = $failureType
            ? [$failureType]
            : [
                OperationInterface::STATUS_TYPE_RETRIABLY_FAILED,
                OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED
            ];
        $operations = $this->operationCollectionFactory->create()
            ->addFieldToFilter('bulk_uuid', $bulkUuid)
            ->addFieldToFilter('status', $failureCodes)
            ->getItems();
        return $operations;
    }

    /**
     * @inheritDoc
     */
    public function getOperationsCountByBulkIdAndStatus($bulkUuid, $status)
    {
        /** @var OperationCollection $operationCollection */
        $operationCollection = $this->operationCollectionFactory->create();
        if ($status === OperationInterface::STATUS_TYPE_OPEN) {
            $allProcessedOperationsQty = $operationCollection
                ->addFieldToFilter('bulk_uuid', $bulkUuid)
                ->getSize();

            if (empty($allProcessedOperationsQty)) {
                return $this->getOperationCount($bulkUuid);
            }

            $operationCollection->clear();
        }

        return $operationCollection
            ->addFieldToFilter('bulk_uuid', $bulkUuid)
            ->addFieldToFilter('status', $status)
            ->getSize();
    }

    /**
     * @inheritDoc
     */
    public function getBulksByUser($userId)
    {
        /** @var ResourceModel\Bulk\Collection $collection */
        $collection = $this->bulkCollectionFactory->create();
        $operationTableName = $this->resourceConnection->getTableName('magento_operation');
        $statusesArray = [
            OperationInterface::STATUS_TYPE_RETRIABLY_FAILED,
            OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED,
            BulkSummaryInterface::NOT_STARTED,
            OperationInterface::STATUS_TYPE_OPEN,
            OperationInterface::STATUS_TYPE_COMPLETE
        ];
        $select = $collection->getSelect();
        $select->columns(['status' => $this->calculatedStatusSql->get($operationTableName)])
            ->order(new \Zend_Db_Expr('FIELD(status, ' . implode(',', $statusesArray) . ')'));
        $collection->addFieldToFilter('user_id', $userId)
            ->addOrder('start_time');

        return $collection->getItems();
    }

    /**
     * @inheritDoc
     */
    public function getBulkStatus($bulkUuid)
    {
        /**
         * Number of operations that has been processed (i.e. operations with any status but 'open')
         */
        $allProcessedOperationsQty = (int)$this->operationCollectionFactory->create()
            ->addFieldToFilter('bulk_uuid', $bulkUuid)
            ->getSize();

        if ($allProcessedOperationsQty == 0) {
            return BulkSummaryInterface::NOT_STARTED;
        }

        /**
         * Total number of operations that has been scheduled within the given bulk
         */
        $allOperationsQty = $this->getOperationCount($bulkUuid);

        /**
         * Number of operations that has not been started yet (i.e. operations with status 'open')
         */
        $allOpenOperationsQty = $allOperationsQty - $allProcessedOperationsQty;

        /**
         * Number of operations that has been completed successfully
         */
        $allCompleteOperationsQty = $this->operationCollectionFactory->create()
            ->addFieldToFilter('bulk_uuid', $bulkUuid)->addFieldToFilter(
                'status',
                OperationInterface::STATUS_TYPE_COMPLETE
            )->getSize();

        if ($allCompleteOperationsQty == $allOperationsQty) {
            return BulkSummaryInterface::FINISHED_SUCCESSFULLY;
        }

        if ($allOpenOperationsQty > 0 && $allOpenOperationsQty !== $allOperationsQty) {
            return BulkSummaryInterface::IN_PROGRESS;
        }

        return BulkSummaryInterface::FINISHED_WITH_FAILURE;
    }

    /**
     * Get total number of operations that has been scheduled within the given bulk.
     *
     * @param string $bulkUuid
     * @return int
     */
    private function getOperationCount($bulkUuid)
    {
        $metadata = $this->metadataPool->getMetadata(BulkSummaryInterface::class);
        $connection = $this->resourceConnection->getConnectionByName($metadata->getEntityConnectionName());

        return (int)$connection->fetchOne(
            $connection->select()
                ->from($metadata->getEntityTable(), 'operation_count')
                ->where('uuid = ?', $bulkUuid)
        );
    }
}
