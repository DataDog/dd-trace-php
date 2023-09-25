<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Signifyd\Model\QuoteSession;

use Magento\Checkout\Model\Session as CheckoutSession;

/**
 * Implementation of QuoteSessionInterface for Magento frontend checkout.
 *
 * @deprecated 100.3.5 Starting from Magento 2.3.5 Signifyd core integration is deprecated in favor of
 * official Signifyd integration available on the marketplace
 */
class FrontendSession implements QuoteSessionInterface
{
    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * FrontendSession constructor.
     *
     * Class uses checkout session for retrieving quote.
     *
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(CheckoutSession $checkoutSession)
    {
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * @inheritdoc
     */
    public function getQuote()
    {
        return $this->checkoutSession->getQuote();
    }
}
