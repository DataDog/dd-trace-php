<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\TestFramework\Mview;

/**
 * Stub for \Magento\Framework\Mview\TriggerCleaner
 */
class DummyTriggerCleaner
{
    /**
     * Remove the outdated trigger from the system
     *
     * @return bool
     */
    public function removeTriggers(): bool
    {
        return true;
    }
}
