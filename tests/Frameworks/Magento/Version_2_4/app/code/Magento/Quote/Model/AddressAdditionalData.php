<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Quote\Model;

use Magento\Quote\Api\Data\AddressAdditionalDataInterface;
use Magento\Framework\Model\AbstractExtensibleModel;

class AddressAdditionalData extends AbstractExtensibleModel implements AddressAdditionalDataInterface
{
    /**
     * Retrieve existing extension attributes object or create a new one.
     *
     * @return \Magento\Quote\Api\Data\AddressAdditionalDataExtensionInterface|null
     */
    public function getExtensionAttributes()
    {
        return $this->_getExtensionAttributes();
    }

    /**
     * {@inheritdoc}
     */
    public function setExtensionAttributes(
        \Magento\Quote\Api\Data\AddressAdditionalDataExtensionInterface $extensionAttributes
    ) {
        $this->_setExtensionAttributes($extensionAttributes);
    }
}
