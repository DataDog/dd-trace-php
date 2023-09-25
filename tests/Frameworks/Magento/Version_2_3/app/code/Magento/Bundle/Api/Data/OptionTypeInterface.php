<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Bundle\Api\Data;

/**
 * Interface OptionTypeInterface
 * @api
 * @since 100.0.2
 */
interface OptionTypeInterface extends \Magento\Framework\Api\ExtensibleDataInterface
{
    /**
     * Get type label
     *
     * @return string
     */
    public function getLabel();

    /**
     * Set type label
     *
     * @param string $label
     * @return $this
     */
    public function setLabel($label);

    /**
     * Get type code
     *
     * @return string
     */
    public function getCode();

    /**
     * Set type code
     *
     * @param string $code
     * @return $this
     */
    public function setCode($code);

    /**
     * Retrieve existing extension attributes object or create a new one.
     *
     * @return \Magento\Bundle\Api\Data\OptionTypeExtensionInterface|null
     */
    public function getExtensionAttributes();

    /**
     * Set an extension attributes object.
     *
     * @param \Magento\Bundle\Api\Data\OptionTypeExtensionInterface $extensionAttributes
     * @return $this
     */
    public function setExtensionAttributes(\Magento\Bundle\Api\Data\OptionTypeExtensionInterface $extensionAttributes);
}
