<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\AsynchronousOperations\Api\Data;

/**
 * Interface BulkStatusInterface
 *
 * Bulk summary data with list of operations items summary data.
 *
 * @api
 * @since 100.2.3
 */
interface BulkOperationsStatusInterface extends BulkSummaryInterface
{

    const OPERATIONS_LIST = 'operations_list';

    /**
     * Retrieve list of operation with statuses (short data).
     *
     * @return \Magento\AsynchronousOperations\Api\Data\SummaryOperationStatusInterface[]
     * @since 100.2.3
     */
    public function getOperationsList();

    /**
     * Set operations list.
     *
     * @param \Magento\AsynchronousOperations\Api\Data\SummaryOperationStatusInterface[] $operationStatusList
     * @return $this
     * @since 100.2.3
     */
    public function setOperationsList($operationStatusList);
}
