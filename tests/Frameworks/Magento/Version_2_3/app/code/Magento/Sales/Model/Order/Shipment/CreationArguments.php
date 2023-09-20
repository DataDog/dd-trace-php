<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Model\Order\Shipment;

/**
 * Creation arguments for Shipment.
 *
 * @api
 * @since 100.1.2
 */
class CreationArguments implements \Magento\Sales\Api\Data\ShipmentCreationArgumentsInterface
{
    /**
     * @var \Magento\Sales\Api\Data\ShipmentCreationArgumentsExtensionInterface
     */
    private $extensionAttributes;

    /**
     * {@inheritdoc}
     * @since 100.1.2
     */
    public function getExtensionAttributes()
    {
        return $this->extensionAttributes;
    }

    /**
     * {@inheritdoc}
     * @since 100.1.2
     */
    public function setExtensionAttributes(
        \Magento\Sales\Api\Data\ShipmentCreationArgumentsExtensionInterface $extensionAttributes
    ) {
        $this->extensionAttributes = $extensionAttributes;
        return $this;
    }
}
