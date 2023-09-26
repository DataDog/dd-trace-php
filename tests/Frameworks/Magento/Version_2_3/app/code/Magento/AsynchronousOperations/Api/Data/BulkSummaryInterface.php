<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\AsynchronousOperations\Api\Data;

/**
 * Interface BulkSummaryInterface
 * @api
 * @since 100.2.0
 */
interface BulkSummaryInterface extends \Magento\Framework\Bulk\BulkSummaryInterface
{
    const USER_TYPE = 'user_type';

    /**
     * Retrieve existing extension attributes object.
     *
     * @return \Magento\AsynchronousOperations\Api\Data\BulkSummaryExtensionInterface|null
     * @since 100.2.0
     */
    public function getExtensionAttributes();

    /**
     * Set an extension attributes object.
     *
     * @param \Magento\AsynchronousOperations\Api\Data\BulkSummaryExtensionInterface $extensionAttributes
     * @return $this
     * @since 100.2.0
     */
    public function setExtensionAttributes(
        \Magento\AsynchronousOperations\Api\Data\BulkSummaryExtensionInterface $extensionAttributes
    );

    /**
     * Get user type
     *
     * @return int
     * @since 100.3.0
     */
    public function getUserType();

    /**
     * Set user type
     *
     * @param int $userType
     * @return $this
     * @since 100.3.0
     */
    public function setUserType($userType);
}
