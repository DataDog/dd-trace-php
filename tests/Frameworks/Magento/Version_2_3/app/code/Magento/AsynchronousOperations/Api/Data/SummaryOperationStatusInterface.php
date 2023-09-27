<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\AsynchronousOperations\Api\Data;

/**
 * Getter Class OperationsStatusInterface
 * Instead of OperationInterface this class don't provide all operation data
 * and not responsive to set any data, just to get operation data
 * without serialized_data and result_serialized_data
 *
 * @api
 * @since 100.2.3
 */
interface SummaryOperationStatusInterface
{
    /**
     * Operation id
     *
     * @return int
     * @since 100.2.3
     */
    public function getId();

    /**
     * Get operation status
     *
     * OPEN | COMPLETE | RETRIABLY_FAILED | NOT_RETRIABLY_FAILED
     *
     * @return int
     * @since 100.2.3
     */
    public function getStatus();

    /**
     * Get result message
     *
     * @return string
     * @since 100.2.3
     */
    public function getResultMessage();

    /**
     * Get error code
     *
     * @return int
     * @since 100.2.3
     */
    public function getErrorCode();
}
