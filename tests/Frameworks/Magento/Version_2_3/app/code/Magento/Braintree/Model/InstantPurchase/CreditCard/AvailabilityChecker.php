<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Braintree\Model\InstantPurchase\CreditCard;

use Magento\Braintree\Gateway\Config\Config;
use Magento\InstantPurchase\PaymentMethodIntegration\AvailabilityCheckerInterface;

/**
 * Availability of Braintree vaults for instant purchase.
 *
 * @deprecated Starting from Magento 2.3.6 Braintree payment method core integration is deprecated
 * in favor of official payment integration available on the marketplace
 */
class AvailabilityChecker implements AvailabilityCheckerInterface
{
    /**
     * @var Config
     */
    private $config;

    /**
     * AvailabilityChecker constructor.
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @inheritdoc
     */
    public function isAvailable(): bool
    {
        if ($this->config->isVerify3DSecure()) {
            // Support of 3D secure not implemented for instant purchase yet.
            return false;
        }

        return true;
    }
}
