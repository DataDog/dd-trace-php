<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Model\ResourceModel\Db;

use Magento\Framework\DB\Adapter\AdapterInterface as Connection;

/**
 * @api
 * @since 100.0.2
 */
class ObjectRelationProcessor
{
    /**
     * Process delete action
     *
     * @param TransactionManagerInterface $transactionManager
     * @param Connection $connection
     * @param string $table
     * @param string $condition
     * @param array $involvedData
     * @return void
     * @throws \LogicException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function delete(
        TransactionManagerInterface $transactionManager,
        Connection $connection,
        $table,
        $condition,
        array $involvedData
    ) {
        $connection->delete($table, $condition);
    }

    /**
     * Validate integrity of the given data
     *
     * @param string $table
     * @param array $involvedData
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function validateDataIntegrity($table, array $involvedData)
    {
        return;
    }
}
