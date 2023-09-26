<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Integration\Api;

/**
 * Interface providing token generation for Admins
 *
 * @api
 * @since 100.0.2
 */
interface AdminTokenServiceInterface
{
    /**
     * Create access token for admin given the admin credentials.
     *
     * @param string $username
     * @param string $password
     * @return string Token created
     * @throws \Magento\Framework\Exception\InputException For invalid input
     * @throws \Magento\Framework\Exception\AuthenticationException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function createAdminAccessToken($username, $password);

    /**
     * Revoke token by admin id.
     *
     * @param int $adminId
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function revokeAdminAccessToken($adminId);
}
