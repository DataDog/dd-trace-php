<?php
/**
 * Event configuration model interface
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Event;

/**
 * Interface \Magento\Framework\Event\ConfigInterface
 *
 */
interface ConfigInterface
{
    /**#@+
     * Event types
     */
    const TYPE_CORE = 'core';
    const TYPE_CUSTOM = 'custom';
    /**#@-*/

    /**
     * Get observers by event name
     *
     * @param string $eventName
     * @return array
     */
    public function getObservers($eventName);
}
