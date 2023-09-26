<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Quote\Api\Data;

use Magento\Framework\Api\CustomAttributesDataInterface;

/**
 * Additional data for totals collection.
 * @api
 * @since 100.0.2
 */
interface TotalsAdditionalDataInterface extends CustomAttributesDataInterface
{
    /**
     * Retrieve existing extension attributes object or create a new one.
     *
     * @return \Magento\Quote\Api\Data\TotalsAdditionalDataExtensionInterface|null
     */
    public function getExtensionAttributes();

    /**
     * Set an extension attributes object.
     *
     * @param \Magento\Quote\Api\Data\TotalsAdditionalDataExtensionInterface $extensionAttributes
     * @return void
     */
    public function setExtensionAttributes(
        \Magento\Quote\Api\Data\TotalsAdditionalDataExtensionInterface $extensionAttributes
    );
}
