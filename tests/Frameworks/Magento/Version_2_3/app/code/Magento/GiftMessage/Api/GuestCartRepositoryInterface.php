<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\GiftMessage\Api;

/**
 * Interface GuestCartRepositoryInterface
 * @api
 * @since 100.0.2
 */
interface GuestCartRepositoryInterface
{
    /**
     * Return the gift message for a specified order.
     *
     * @param string $cartId The shopping cart ID.
     * @return \Magento\GiftMessage\Api\Data\MessageInterface Gift message.
     */
    public function get($cartId);

    /**
     * Set the gift message for an entire order.
     *
     * @param string $cartId The cart ID.
     * @param \Magento\GiftMessage\Api\Data\MessageInterface $giftMessage The gift message.
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException The specified cart does not exist.
     * @throws \Magento\Framework\Exception\InputException You cannot add gift messages to empty carts.
     * @throws \Magento\Framework\Exception\CouldNotSaveException The specified gift message could not be saved.
     */
    public function save($cartId, \Magento\GiftMessage\Api\Data\MessageInterface $giftMessage);
}
