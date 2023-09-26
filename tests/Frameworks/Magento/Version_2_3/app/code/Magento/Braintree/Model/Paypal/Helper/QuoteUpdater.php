<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Braintree\Model\Paypal\Helper;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Payment;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Braintree\Model\Ui\PayPal\ConfigProvider;
use Magento\Framework\Exception\LocalizedException;
use Magento\Braintree\Observer\DataAssignObserver;
use Magento\Braintree\Gateway\Config\PayPal\Config;

/**
 * Class QuoteUpdater
 *
 * @deprecated Starting from Magento 2.3.6 Braintree payment method core integration is deprecated
 * in favor of official payment integration available on the marketplace
 */
class QuoteUpdater extends AbstractHelper
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * Constructor
     *
     * @param Config $config
     * @param CartRepositoryInterface $quoteRepository
     */
    public function __construct(
        Config $config,
        CartRepositoryInterface $quoteRepository
    ) {
        $this->config = $config;
        $this->quoteRepository = $quoteRepository;
    }

    /**
     * Execute operation
     *
     * @param string $nonce
     * @param array $details
     * @param Quote $quote
     * @return void
     * @throws \InvalidArgumentException
     * @throws LocalizedException
     */
    public function execute($nonce, array $details, Quote $quote)
    {
        if (empty($nonce) || empty($details)) {
            throw new \InvalidArgumentException('The "nonce" and "details" fields does not exists');
        }

        $payment = $quote->getPayment();

        $payment->setMethod(ConfigProvider::PAYPAL_CODE);
        $payment->setAdditionalInformation(DataAssignObserver::PAYMENT_METHOD_NONCE, $nonce);

        $this->updateQuote($quote, $details);
    }

    /**
     * Update quote data
     *
     * @param Quote $quote
     * @param array $details
     * @return void
     */
    private function updateQuote(Quote $quote, array $details)
    {
        $quote->setMayEditShippingAddress(false);
        $quote->setMayEditShippingMethod(true);

        $this->updateQuoteAddress($quote, $details);
        $this->disabledQuoteAddressValidation($quote);

        $quote->collectTotals();

        /**
         * Unset shipping assignment to prevent from saving / applying outdated data
         * @see \Magento\Quote\Model\QuoteRepository\SaveHandler::processShippingAssignment
         */
        if ($quote->getExtensionAttributes()) {
            $quote->getExtensionAttributes()->setShippingAssignments(null);
        }

        $this->quoteRepository->save($quote);
    }

    /**
     * Update quote address
     *
     * @param Quote $quote
     * @param array $details
     * @return void
     */
    private function updateQuoteAddress(Quote $quote, array $details)
    {
        if (!$quote->getIsVirtual()) {
            $this->updateShippingAddress($quote, $details);
        }

        $this->updateBillingAddress($quote, $details);
    }

    /**
     * Update shipping address
     * (PayPal doesn't provide detailed shipping info: prefix, suffix)
     *
     * @param Quote $quote
     * @param array $details
     * @return void
     */
    private function updateShippingAddress(Quote $quote, array $details)
    {
        $shippingAddress = $quote->getShippingAddress();

        $shippingAddress->setLastname($this->getShippingRecipientLastName($details));
        $shippingAddress->setFirstname($this->getShippingRecipientFirstName($details));
        $shippingAddress->setEmail($details['email']);

        $shippingAddress->setCollectShippingRates(true);

        $this->updateAddressData($shippingAddress, $details['shippingAddress']);

        // PayPal's address supposes not saving against customer account
        $shippingAddress->setSaveInAddressBook(false);
        $shippingAddress->setSameAsBilling(false);
        $shippingAddress->unsCustomerAddressId();
    }

    /**
     * Update billing address
     *
     * @param Quote $quote
     * @param array $details
     * @return void
     */
    private function updateBillingAddress(Quote $quote, array $details)
    {
        $billingAddress = $quote->getBillingAddress();

        if ($this->config->isRequiredBillingAddress() && !empty($details['billingAddress'])) {
            $this->updateAddressData($billingAddress, $details['billingAddress']);
        } else {
            $this->updateAddressData($billingAddress, $details['shippingAddress']);
        }

        $billingAddress->setFirstname($details['firstName']);
        $billingAddress->setLastname($details['lastName']);
        $billingAddress->setEmail($details['email']);

        // PayPal's address supposes not saving against customer account
        $billingAddress->setSaveInAddressBook(false);
        $billingAddress->setSameAsBilling(false);
        $billingAddress->unsCustomerAddressId();
    }

    /**
     * Sets address data from exported address
     *
     * @param Address $address
     * @param array $addressData
     * @return void
     */
    private function updateAddressData(Address $address, array $addressData)
    {
        $extendedAddress = isset($addressData['line2'])
            ? $addressData['line2']
            : null;

        $address->setStreet([$addressData['line1'], $extendedAddress]);
        $address->setCity($addressData['city']);
        $address->setRegionCode($addressData['state']);
        $address->setCountryId($addressData['countryCode']);
        $address->setPostcode($addressData['postalCode']);

        // PayPal's address supposes not saving against customer account
        $address->setSaveInAddressBook(false);
        $address->setSameAsBilling(false);
        $address->setCustomerAddressId(null);
    }

    /**
     * Returns shipping recipient first name.
     *
     * @param array $details
     * @return string
     */
    private function getShippingRecipientFirstName(array $details)
    {
        return isset($details['shippingAddress']['recipientName'])
            ? explode(' ', $details['shippingAddress']['recipientName'], 2)[0]
            : $details['firstName'];
    }

    /**
     * Returns shipping recipient last name.
     *
     * @param array $details
     * @return string
     */
    private function getShippingRecipientLastName(array $details)
    {
        return isset($details['shippingAddress']['recipientName'])
            ? explode(' ', $details['shippingAddress']['recipientName'], 2)[1]
            : $details['lastName'];
    }
}
