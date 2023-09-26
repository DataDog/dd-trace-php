<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Bundle\Model\Product\Attribute\Source\Shipment;

/**
 * Bundle Shipment Type Attribute Renderer
 * @api
 * @since 100.1.0
 */
class Type extends \Magento\Eav\Model\Entity\Attribute\Source\AbstractSource
{
    /**
     * {@inheritdoc}
     * @since 100.1.0
     */
    public function getAllOptions()
    {
        if (null === $this->_options) {
            $this->_options = [
                ['label' => __('Together'), 'value' => 0],
                ['label' => __('Separately'), 'value' => 1],
            ];
        }
        return $this->_options;
    }

    /**
     * {@inheritdoc}
     * @since 100.1.0
     */
    public function getOptionText($value)
    {
        foreach ($this->getAllOptions() as $option) {
            if ($option['value'] == $value) {
                return $option['label'];
            }
        }
        return false;
    }
}
