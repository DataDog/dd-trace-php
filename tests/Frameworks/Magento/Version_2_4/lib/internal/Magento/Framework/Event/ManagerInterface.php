<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Event;

/**
 * Interface \Magento\Framework\Event\ManagerInterface
 *
 * @api
 */
interface ManagerInterface
{
    /**
     * Dispatch event
     *
     * Calls all observer callbacks registered for this event
     * and multiple observers matching event name pattern
     *
     * @param string $eventName
     * @param array $data
     * @return void
     */
    public function dispatch($eventName, array $data = []);
}
