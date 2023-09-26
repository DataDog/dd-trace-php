<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Lock;

/**
 * Interface of a lock manager
 *
 * @api
 * @since 101.0.5
 */
interface LockManagerInterface
{
    /**
     * Sets a lock
     *
     * @param string $name lock name
     * @param int $timeout How long to wait lock acquisition in seconds, negative value means infinite timeout
     * @return bool
     * @api
     * @since 101.0.5
     */
    public function lock(string $name, int $timeout = -1): bool;

    /**
     * Releases a lock
     *
     * @param string $name lock name
     * @return bool
     * @api
     * @since 101.0.5
     */
    public function unlock(string $name): bool;

    /**
     * Tests if lock is set
     *
     * @param string $name lock name
     * @return bool
     * @api
     * @since 101.0.5
     */
    public function isLocked(string $name): bool;
}
