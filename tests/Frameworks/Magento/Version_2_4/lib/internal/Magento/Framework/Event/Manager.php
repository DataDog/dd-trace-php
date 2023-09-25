<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Event;

/**
 * Event manager used to dispatch global events.
 */
class Manager implements ManagerInterface
{
    /**
     * Events cache
     *
     * @var array
     */
    protected $_events = [];

    /**
     * Event invoker
     *
     * @var InvokerInterface
     */
    protected $_invoker;

    /**
     * @var ConfigInterface
     */
    protected $_eventConfig;

    /**
     * @param InvokerInterface $invoker
     * @param ConfigInterface $eventConfig
     */
    public function __construct(InvokerInterface $invoker, ConfigInterface $eventConfig)
    {
        $this->_invoker = $invoker;
        $this->_eventConfig = $eventConfig;
    }

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
    public function dispatch($eventName, array $data = [])
    {
        $eventName = $eventName !== null ? mb_strtolower($eventName) : '';
        \Magento\Framework\Profiler::start('EVENT:' . $eventName, ['group' => 'EVENT', 'name' => $eventName]);
        foreach ($this->_eventConfig->getObservers($eventName) as $observerConfig) {
            $event = new \Magento\Framework\Event($data);
            $event->setName($eventName);

            $wrapper = new Observer();
            // phpcs:ignore Magento2.Performance.ForeachArrayMerge
            $wrapper->setData(array_merge(['event' => $event], $data));

            \Magento\Framework\Profiler::start('OBSERVER:' . $observerConfig['name']);
            $this->_invoker->dispatch($observerConfig, $wrapper);
            \Magento\Framework\Profiler::stop('OBSERVER:' . $observerConfig['name']);
        }
        \Magento\Framework\Profiler::stop('EVENT:' . $eventName);
    }
}
