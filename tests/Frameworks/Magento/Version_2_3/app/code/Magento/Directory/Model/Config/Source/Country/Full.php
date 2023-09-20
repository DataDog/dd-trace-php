<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Directory\Model\Config\Source\Country;

/**
 * Options provider for full countries list
 *
 * @api
 *
 * @codeCoverageIgnore
 * @since 100.0.2
 */
class Full extends \Magento\Directory\Model\Config\Source\Country implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray($isMultiselect = false, $foregroundCountries = '')
    {
        return parent::toOptionArray(true, $foregroundCountries);
    }
}
