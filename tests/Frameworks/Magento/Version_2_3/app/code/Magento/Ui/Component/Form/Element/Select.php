<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Ui\Component\Form\Element;

/**
 * @api
 * @since 100.0.2
 */
class Select extends AbstractOptionsField
{
    const NAME = 'select';

    /**
     * Get component name
     *
     * @return string
     */
    public function getComponentName()
    {
        return static::NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function getIsSelected($optionValue)
    {
        return $this->getValue() == $optionValue;
    }
}
