<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\TestModule3\Service\V1\Entity;

class Parameter extends \Magento\Framework\Api\AbstractExtensibleObject
{
    /**
     * Get Name.
     *
     * @return string $name
     */
    public function getName()
    {
        return $this->_data['name'];
    }

    /**
     * Set Name.
     *
     * @param string $name
     * @return $this
     */
    public function setName($name)
    {
        return $this->setData('name', $name);
    }

    /**
     * Get value.
     *
     * @return string $value
     */
    public function getValue()
    {
        return $this->_data['value'];
    }

    /**
     * Set value.
     *
     * @param string $value
     * @return $this
     */
    public function setValue($value)
    {
        return $this->setData('value', $value);
    }
}
