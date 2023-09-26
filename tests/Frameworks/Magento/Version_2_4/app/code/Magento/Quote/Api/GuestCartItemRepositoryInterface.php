<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Quote\Api;

/**
 * Cart Item repository interface for guest carts.
 * @api
 * @since 100.0.2
 */
interface GuestCartItemRepositoryInterface
{
    /**
     * List items that are assigned to a specified cart.
     *
     * @param string $cartId The cart ID.
     * @return \Magento\Quote\Api\Data\CartItemInterface[] Array of items.
     * @throws \Magento\Framework\Exception\NoSuchEntityException The specified cart does not exist.
     */
    public function getList($cartId);

    /**
     * Add/update the specified cart item.
     *
     * @param \Magento\Quote\Api\Data\CartItemInterface $cartItem The item.
     * @return \Magento\Quote\Api\Data\CartItemInterface Item.
     * @throws \Magento\Framework\Exception\NoSuchEntityException The specified cart does not exist.
     * @throws \Magento\Framework\Exception\CouldNotSaveException The specified item could not be saved to the cart.
     * @throws \Magento\Framework\Exception\InputException The specified item or cart is not valid.
     */
    public function save(\Magento\Quote\Api\Data\CartItemInterface $cartItem);

    /**
     * Remove the specified item from the specified cart.
     *
     * @param string $cartId The cart ID.
     * @param int $itemId The item ID of the item to be removed.
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException The specified item or cart does not exist.
     * @throws \Magento\Framework\Exception\CouldNotSaveException The item could not be removed.
     */
    public function deleteById($cartId, $itemId);
}
