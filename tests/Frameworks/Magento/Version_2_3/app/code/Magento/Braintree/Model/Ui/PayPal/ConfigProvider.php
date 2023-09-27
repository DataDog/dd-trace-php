<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Braintree\Model\Ui\PayPal;

use Magento\Braintree\Gateway\Config\PayPal\Config;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\Locale\ResolverInterface;

/**
 * Class ConfigProvider
 *
 * @deprecated Starting from Magento 2.3.6 Braintree payment method core integration is deprecated
 * in favor of official payment integration available on the marketplace
 */
class ConfigProvider implements ConfigProviderInterface
{
    const PAYPAL_CODE = 'braintree_paypal';

    const PAYPAL_VAULT_CODE = 'braintree_paypal_vault';

    /**
     * @var Config
     */
    private $config;

    /**
     * @var ResolverInterface
     */
    private $resolver;

    /**
     * Initialize dependencies.
     *
     * @param Config $config
     * @param ResolverInterface $resolver
     */
    public function __construct(Config $config, ResolverInterface $resolver)
    {
        $this->config = $config;
        $this->resolver = $resolver;
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        $requireBillingAddressAll = \Magento\Paypal\Model\Config::REQUIRE_BILLING_ADDRESS_ALL;

        return [
            'payment' => [
                self::PAYPAL_CODE => [
                    'isActive' => $this->config->isActive(),
                    'title' => $this->config->getTitle(),
                    'isAllowShippingAddressOverride' => $this->config->isAllowToEditShippingAddress(),
                    'merchantName' => $this->config->getMerchantName(),
                    'locale' => $this->resolver->getLocale(),
                    'paymentAcceptanceMarkSrc' =>
                        'https://www.paypalobjects.com/webstatic/en_US/i/buttons/pp-acceptance-medium.png',
                    'vaultCode' => self::PAYPAL_VAULT_CODE,
                    'skipOrderReview' => $this->config->isSkipOrderReview(),
                    'paymentIcon' => $this->config->getPayPalIcon(),
                    'isRequiredBillingAddress' =>
                        (int)$this->config->isRequiredBillingAddress() === $requireBillingAddressAll
                ]
            ]
        ];
    }
}
