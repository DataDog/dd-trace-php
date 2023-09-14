<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Api\Data;

use Magento\Framework\Api\ExtensibleDataInterface;

/**
 * Interface CreditmemoCommentCreationInterface
 *
 * @api
 * @since 100.1.3
 */
interface CreditmemoCommentCreationInterface extends ExtensibleDataInterface, CommentInterface
{
    /**
     * Retrieve existing extension attributes object or create a new one.
     *
     * @return \Magento\Sales\Api\Data\CreditmemoCommentCreationExtensionInterface|null
     * @since 100.1.3
     */
    public function getExtensionAttributes();

    /**
     * Set an extension attributes object.
     *
     * @param \Magento\Sales\Api\Data\CreditmemoCommentCreationExtensionInterface $extensionAttributes
     * @return $this
     * @since 100.1.3
     */
    public function setExtensionAttributes(
        \Magento\Sales\Api\Data\CreditmemoCommentCreationExtensionInterface $extensionAttributes
    );
}
