<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Quote\Model;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface as Logger;

/**
 * Quote shipping address write service object.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ShippingAddressManagement implements \Magento\Quote\Model\ShippingAddressManagementInterface
{
    /**
     * Quote repository.
     *
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    protected $quoteRepository;

    /**
     * Logger.
     *
     * @var Logger
     */
    protected $logger;

    /**
     * Validator.
     *
     * @var QuoteAddressValidator
     */
    protected $addressValidator;

    /**
     * @var \Magento\Customer\Api\AddressRepositoryInterface
     */
    protected $addressRepository;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var Quote\TotalsCollector
     */
    protected $totalsCollector;

    /**
     * @param \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
     * @param QuoteAddressValidator $addressValidator
     * @param Logger $logger
     * @param \Magento\Customer\Api\AddressRepositoryInterface $addressRepository
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param Quote\TotalsCollector $totalsCollector
     *
     */
    public function __construct(
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        QuoteAddressValidator $addressValidator,
        Logger $logger,
        \Magento\Customer\Api\AddressRepositoryInterface $addressRepository,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\TotalsCollector $totalsCollector
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->addressValidator = $addressValidator;
        $this->logger = $logger;
        $this->addressRepository = $addressRepository;
        $this->scopeConfig = $scopeConfig;
        $this->totalsCollector = $totalsCollector;
    }

    /**
     * @inheritDoc
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function assign($cartId, \Magento\Quote\Api\Data\AddressInterface $address)
    {
        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $this->quoteRepository->getActive($cartId);
        if ($quote->isVirtual()) {
            throw new NoSuchEntityException(
                __('The Cart includes virtual product(s) only, so a shipping address is not used.')
            );
        }

        $saveInAddressBook = $address->getSaveInAddressBook() ? 1 : 0;
        $sameAsBilling = $address->getSameAsBilling() ? 1 : 0;
        $customerAddressId = $address->getCustomerAddressId();
        $this->addressValidator->validateForCart($quote, $address);
        $quote->setShippingAddress($address);
        $address = $quote->getShippingAddress();

        if ($customerAddressId === null) {
            $address->setCustomerAddressId(null);
        }

        if ($customerAddressId) {
            $addressData = $this->addressRepository->getById($customerAddressId);
            $address = $quote->getShippingAddress()->importCustomerAddressData($addressData);
        } elseif ($quote->getCustomerId()) {
            $address->setEmail($quote->getCustomerEmail());
        }
        $address->setSameAsBilling($sameAsBilling);
        $address->setSaveInAddressBook($saveInAddressBook);
        $address->setCollectShippingRates(true);

        try {
            $address->save();
        } catch (\Exception $e) {
            $this->logger->critical($e);
            throw new InputException(__('The address failed to save. Verify the address and try again.'));
        }
        return $quote->getShippingAddress()->getId();
    }

    /**
     * @inheritDoc
     */
    public function get($cartId)
    {
        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $this->quoteRepository->getActive($cartId);
        if ($quote->isVirtual()) {
            throw new NoSuchEntityException(
                __('The Cart includes virtual product(s) only, so a shipping address is not used.')
            );
        }
        /** @var \Magento\Quote\Model\Quote\Address $address */
        return $quote->getShippingAddress();
    }
}
