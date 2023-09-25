<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Pricing\Price;

/**
 * The price pool
 *
 * @api
 * @since 100.0.2
 */
class Pool implements \Iterator, \ArrayAccess
{
    /**
     * @var \Magento\Framework\Pricing\Price\PriceInterface[]
     */
    protected $prices;

    /**
     * @param array $prices
     * @param \Iterator $target
     */
    public function __construct(
        array $prices,
        \Iterator $target = null
    ) {
        $this->prices = $prices;
        foreach ($target ?: [] as $code => $class) {
            if (empty($this->prices[$code])) {
                $this->prices[$code] = $class;
            }
        }
    }

    /**
     * Reset the Collection to the first element
     *
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function rewind()
    {
        return reset($this->prices);
    }

    /**
     * Return the current element
     *
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        return current($this->prices);
    }

    /**
     * Return the key of the current element
     *
     * @return string
     */
    #[\ReturnTypeWillChange]
    public function key()
    {
        return key($this->prices);
    }

    /**
     * Move forward to next element
     *
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function next()
    {
        return next($this->prices);
    }

    /**
     * Checks if current position is valid
     *
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function valid()
    {
        return (bool)$this->key();
    }

    /**
     * Returns price class by code
     *
     * @param string $code
     * @return string
     */
    public function get($code)
    {
        return $this->prices[$code];
    }

    /**
     * The value to set.
     *
     * @param string $offset
     * @param string $value
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        if ($offset === null) {
            $this->prices[] = $value;
        } else {
            $this->prices[$offset] = $value;
        }
    }

    /**
     * The return value will be casted to boolean if non-boolean was returned.
     *
     * @param string $offset
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return isset($this->prices[$offset]);
    }

    /**
     * The offset to unset.
     *
     * @param string $offset
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->prices[$offset]);
    }

    /**
     * The offset to retrieve.
     *
     * @param string $offset
     * @return string
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->prices[$offset] ?? null;
    }
}
