<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Braintree\Model\Paypal\Helper;

use Magento\Braintree\Model\Paypal\OrderCancellationService;
use Magento\Checkout\Api\AgreementsValidatorInterface;
use Magento\Checkout\Helper\Data;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Customer\Model\Group;
use Magento\Customer\Model\Session;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\Quote;

/**
 * Class OrderPlace
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 * @deprecated Starting from Magento 2.3.6 Braintree payment method core integration is deprecated
 * in favor of official payment integration available on the marketplace
 */
class OrderPlace extends AbstractHelper
{
    /**
     * @var CartManagementInterface
     */
    private $cartManagement;

    /**
     * @var AgreementsValidatorInterface
     */
    private $agreementsValidator;

    /**
     * @var Session
     */
    private $customerSession;

    /**
     * @var Data
     */
    private $checkoutHelper;

    /**
     * @var OrderCancellationService
     */
    private $orderCancellationService;

    /**
     * @param CartManagementInterface $cartManagement
     * @param AgreementsValidatorInterface $agreementsValidator
     * @param Session $customerSession
     * @param Data $checkoutHelper
     * @param OrderCancellationService $orderCancellationService
     */
    public function __construct(
        CartManagementInterface $cartManagement,
        AgreementsValidatorInterface $agreementsValidator,
        Session $customerSession,
        Data $checkoutHelper,
        OrderCancellationService $orderCancellationService
    ) {
        $this->cartManagement = $cartManagement;
        $this->agreementsValidator = $agreementsValidator;
        $this->customerSession = $customerSession;
        $this->checkoutHelper = $checkoutHelper;
        $this->orderCancellationService = $orderCancellationService;
    }

    /**
     * Execute operation
     *
     * @param Quote $quote
     * @param array $agreement
     * @return void
     * @throws \Exception
     */
    public function execute(Quote $quote, array $agreement)
    {
        if (!$this->agreementsValidator->isValid($agreement)) {
            $errorMsg = __(
                "The order wasn't placed. First, agree to the terms and conditions, then try placing your order again."
            );
            throw new LocalizedException($errorMsg);
        }

        if ($this->getCheckoutMethod($quote) === Onepage::METHOD_GUEST) {
            $this->prepareGuestQuote($quote);
        }

        $this->disabledQuoteAddressValidation($quote);

        $quote->collectTotals();
        $this->cartManagement->placeOrder($quote->getId());
    }

    /**
     * Get checkout method
     *
     * @param Quote $quote
     * @return string
     */
    private function getCheckoutMethod(Quote $quote)
    {
        if ($this->customerSession->isLoggedIn()) {
            return Onepage::METHOD_CUSTOMER;
        }
        if (!$quote->getCheckoutMethod()) {
            if ($this->checkoutHelper->isAllowedGuestCheckout($quote)) {
                $quote->setCheckoutMethod(Onepage::METHOD_GUEST);
            } else {
                $quote->setCheckoutMethod(Onepage::METHOD_REGISTER);
            }
        }

        return $quote->getCheckoutMethod();
    }

    /**
     * Prepare quote for guest checkout order submit
     *
     * @param Quote $quote
     * @return void
     */
    private function prepareGuestQuote(Quote $quote)
    {
        $quote->setCustomerId(null)
            ->setCustomerEmail($quote->getBillingAddress()->getEmail())
            ->setCustomerIsGuest(true)
            ->setCustomerGroupId(Group::NOT_LOGGED_IN_ID);
    }
}
