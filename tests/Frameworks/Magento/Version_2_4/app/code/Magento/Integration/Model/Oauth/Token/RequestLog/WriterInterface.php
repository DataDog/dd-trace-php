<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Integration\Model\Oauth\Token\RequestLog;

/**
 * OAuth token request log writer interface.
 *
 * @api
 */
interface WriterInterface
{
    /**
     * Reset number of authentication failures for the specified user account.
     *
     * @param string $userName
     * @param int $userType
     * @param return void
     * @return void
     */
    public function resetFailuresCount($userName, $userType);

    /**
     * Increment number of authentication failures for the specified user account.
     *
     * @param string $userName
     * @param int $userType
     * @param return void
     * @return void
     */
    public function incrementFailuresCount($userName, $userType);

    /**
     * Clear expired authentication failure logs.
     *
     * @return void
     */
    public function clearExpiredFailures();
}
