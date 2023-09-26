<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\MessageQueue\Topology\Config\QueueConfigItem;

use Magento\Framework\MessageQueue\Topology\Config\QueueConfigItem;
use Magento\Framework\MessageQueue\Topology\Config\QueueConfigItemFactory;

/**
 * Queue config item iterator.
 */
class Iterator implements \Iterator, \ArrayAccess
{
    /**
     * Consumer config item.
     *
     * @var QueueConfigItem
     */
    private $object;

    /**
     * Config data.
     *
     * @var array
     */
    private $data;

    /**
     * Initialize dependencies.
     *
     * @param DataMapper $configData
     * @param QueueConfigItemFactory $itemFactory
     */
    public function __construct(DataMapper $configData, QueueConfigItemFactory $itemFactory)
    {
        $this->data = $configData->getMappedData();
        $this->object = $itemFactory->create();
        $this->rewind();
    }

    /**
     * Get current item.
     *
     * @return QueueConfigItem
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        return $this->object;
    }

    /**
     * @inheritdoc
     */
    #[\ReturnTypeWillChange]
    public function next()
    {
        next($this->data);
        if (current($this->data)) {
            $this->initObject(current($this->data));
        }
    }

    /**
     * Initialize object.
     *
     * @param array $data
     * @return void
     */
    private function initObject(array $data)
    {
        $this->object->setData($data);
    }

    /**
     * @inheritdoc
     */
    #[\ReturnTypeWillChange]
    public function key()
    {
        key($this->data);
    }

    /**
     * @inheritdoc
     */
    #[\ReturnTypeWillChange]
    public function valid()
    {
        return (bool)current($this->data);
    }

    /**
     * @inheritdoc
     */
    #[\ReturnTypeWillChange]
    public function rewind()
    {
        reset($this->data);
        if (current($this->data)) {
            $this->initObject(current($this->data));
        }
    }

    /**
     * @inheritdoc
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return array_key_exists($offset, $this->data);
    }

    /**
     * @inheritdoc
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        if (!$this->offsetExists($offset)) {
            return null;
        }
        $item = clone $this->object;
        $item->setData($this->data[$offset]);
        return $item;
    }

    /**
     * @inheritdoc
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        $this->data[$offset] = $value;
    }

    /**
     * @inheritdoc
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->data[$offset]);
    }
}
