<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Sales\Api\Data;

use Magento\Framework\Api\ExtensibleDataInterface;

/**
 * Input argument for invoice creation
 *
 * Interface InvoiceItemCreationInterface
 *
 * @api
 * @since 100.1.2
 */
interface InvoiceItemCreationInterface extends LineItemInterface, ExtensibleDataInterface
{
    /**
     * Retrieve existing extension attributes object or create a new one.
     *
     * @return \Magento\Sales\Api\Data\InvoiceItemCreationExtensionInterface|null
     * @since 100.1.2
     */
    public function getExtensionAttributes();

    /**
     * Set an extension attributes object.
     *
     * @param \Magento\Sales\Api\Data\InvoiceItemCreationExtensionInterface $extensionAttributes
     * @return $this
     * @since 100.1.2
     */
    public function setExtensionAttributes(
        \Magento\Sales\Api\Data\InvoiceItemCreationExtensionInterface $extensionAttributes
    );
}
