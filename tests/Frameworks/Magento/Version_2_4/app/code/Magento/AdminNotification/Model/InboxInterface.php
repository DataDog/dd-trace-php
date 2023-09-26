<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\AdminNotification\Model;

/**
 * AdminNotification Inbox interface
 *
 * @api
 * @since 100.0.2
 */
interface InboxInterface
{
    /**
     * Retrieve Severity collection array
     *
     * @param int|null $severity
     * @return array|string|null
     */
    public function getSeverities($severity = null);

    /**
     * Retrieve Latest Notice
     *
     * @return $this
     */
    public function loadLatestNotice();

    /**
     * Retrieve notice statuses
     *
     * @return array
     */
    public function getNoticeStatus();
}
