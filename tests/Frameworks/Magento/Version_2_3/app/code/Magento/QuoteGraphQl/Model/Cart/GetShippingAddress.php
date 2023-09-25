<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\QuoteGraphQl\Model\Cart;

use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Quote\Model\Quote\Address;
use Magento\QuoteGraphQl\Model\Cart\Address\SaveQuoteAddressToCustomerAddressBook;

/**
 * Get shipping address
 */
class GetShippingAddress
{
    /**
     * @var QuoteAddressFactory
     */
    private $quoteAddressFactory;

    /**
     * @var SaveQuoteAddressToCustomerAddressBook
     */
    private $saveQuoteAddressToCustomerAddressBook;

    /**
     * @param QuoteAddressFactory $quoteAddressFactory
     * @param SaveQuoteAddressToCustomerAddressBook $saveQuoteAddressToCustomerAddressBook
     */
    public function __construct(
        QuoteAddressFactory $quoteAddressFactory,
        SaveQuoteAddressToCustomerAddressBook $saveQuoteAddressToCustomerAddressBook
    ) {
        $this->quoteAddressFactory = $quoteAddressFactory;
        $this->saveQuoteAddressToCustomerAddressBook = $saveQuoteAddressToCustomerAddressBook;
    }

    /**
     * Get Shipping Address based on the input.
     *
     * @param ContextInterface $context
     * @param array $shippingAddressInput
     * @return Address
     * @throws GraphQlAuthorizationException
     * @throws GraphQlInputException
     * @throws GraphQlNoSuchEntityException
     */
    public function execute(ContextInterface $context, array $shippingAddressInput): Address
    {
        $customerAddressId = $shippingAddressInput['customer_address_id'] ?? null;
        $addressInput = $shippingAddressInput['address'] ?? null;

        if ($addressInput) {
            $addressInput['customer_notes'] = $shippingAddressInput['customer_notes'] ?? '';
        }

        if (null === $customerAddressId && null === $addressInput) {
            throw new GraphQlInputException(
                __('The shipping address must contain either "customer_address_id" or "address".')
            );
        }

        if ($customerAddressId && $addressInput) {
            throw new GraphQlInputException(
                __('The shipping address cannot contain "customer_address_id" and "address" at the same time.')
            );
        }

        $shippingAddress = $this->createShippingAddress($context, $customerAddressId, $addressInput);

        return $shippingAddress;
    }

    /**
     * Create shipping address.
     *
     * @param ContextInterface $context
     * @param int|null $customerAddressId
     * @param array|null $addressInput
     *
     * @return \Magento\Quote\Model\Quote\Address
     * @throws GraphQlAuthorizationException
     */
    private function createShippingAddress(
        ContextInterface $context,
        ?int $customerAddressId,
        ?array $addressInput
    ) {
        $customerId = $context->getUserId();

        if (null === $customerAddressId) {
            $shippingAddress = $this->quoteAddressFactory->createBasedOnInputData($addressInput);

            // need to save address only for registered user and if save_in_address_book = true
            if (0 !== $customerId
                && isset($addressInput['save_in_address_book'])
                && (bool)$addressInput['save_in_address_book'] === true
            ) {
                $this->saveQuoteAddressToCustomerAddressBook->execute($shippingAddress, $customerId);
            }
        } else {
            if (false === $context->getExtensionAttributes()->getIsCustomer()) {
                throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
            }

            $shippingAddress = $this->quoteAddressFactory->createBasedOnCustomerAddress(
                (int)$customerAddressId,
                $customerId
            );
        }

        return $shippingAddress;
    }
}
