<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\QuoteGraphQl\Model\Cart;

use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Extension point for setting shipping addresses for a specified shopping cart
 * All objects that are responsible for setting shipping addresses on a cart via GraphQl
 * should implement this interface.
 *
 * @api
 */
interface SetShippingAddressesOnCartInterface
{
    /**
     * Set shipping addresses for a specified shopping cart
     *
     * @param ContextInterface $context
     * @param CartInterface $cart
     * @param array $shippingAddressesInput
     * @return void
     * @throws GraphQlInputException
     * @throws GraphQlAuthorizationException
     * @throws GraphQlNoSuchEntityException
     */
    public function execute(ContextInterface $context, CartInterface $cart, array $shippingAddressesInput): void;
}
