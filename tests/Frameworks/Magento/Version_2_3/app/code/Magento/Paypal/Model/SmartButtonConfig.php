<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Paypal\Model;

use Magento\Checkout\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Smart button configuration.
 */
class SmartButtonConfig
{
    /**
     * @var \Magento\Framework\Locale\ResolverInterface
     */
    private $localeResolver;

    /**
     * @var ConfigFactory
     */
    private $config;

    /**
     * @var array
     */
    private $defaultStyles;

    /**
     * @var array
     */
    private $allowedFunding;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param ResolverInterface $localeResolver
     * @param ConfigFactory $configFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param array $defaultStyles
     * @param array $allowedFunding
     */
    public function __construct(
        ResolverInterface $localeResolver,
        ConfigFactory $configFactory,
        ScopeConfigInterface $scopeConfig,
        $defaultStyles = [],
        $allowedFunding = []
    ) {
        $this->localeResolver = $localeResolver;
        $this->config = $configFactory->create();
        $this->config->setMethod(Config::METHOD_EXPRESS);
        $this->scopeConfig = $scopeConfig;
        $this->defaultStyles = $defaultStyles;
        $this->allowedFunding = $allowedFunding;
    }

    /**
     * Get smart button config
     *
     * @param string $page
     * @return array
     */
    public function getConfig(string $page): array
    {
        $isGuestCheckoutAllowed = $this->scopeConfig->isSetFlag(
            Data::XML_PATH_GUEST_CHECKOUT,
            ScopeInterface::SCOPE_STORE
        );
        return [
            'merchantId' => $this->config->getValue('merchant_id'),
            'environment' => ((int)$this->config->getValue('sandbox_flag') ? 'sandbox' : 'production'),
            'locale' => $this->localeResolver->getLocale(),
            'allowedFunding' => $this->getAllowedFunding($page),
            'disallowedFunding' => $this->getDisallowedFunding(),
            'styles' => $this->getButtonStyles($page),
            'isVisibleOnProductPage'  => (bool)$this->config->getValue('visible_on_product'),
            'isGuestCheckoutAllowed'  => $isGuestCheckoutAllowed
        ];
    }

    /**
     * Returns disallowed funding from configuration
     *
     * @return array
     */
    private function getDisallowedFunding(): array
    {
        $disallowedFunding = $this->config->getValue('disable_funding_options');
        $result = $disallowedFunding ? explode(',', $disallowedFunding) : [];

        // PayPal Guest Checkout Credit Card Icons only available when Guest Checkout option is enabled
        if ($this->isPaypalGuestCheckoutAllowed() === false && !in_array('CARD', $result)) {
            array_push($result, 'CARD');
        }

        return $result;
    }

    /**
     * Returns allowed funding
     *
     * @param string $page
     * @return array
     */
    private function getAllowedFunding(string $page): array
    {
        return array_values(array_diff($this->allowedFunding[$page], $this->getDisallowedFunding()));
    }

    /**
     * Returns button styles based on configuration
     *
     * @param string $page
     * @return array
     */
    private function getButtonStyles(string $page): array
    {
        $styles = $this->defaultStyles[$page];
        if ((boolean)$this->config->getValue("{$page}_page_button_customize")) {
            $styles['layout'] = $this->config->getValue("{$page}_page_button_layout");
            $styles['size'] = $this->config->getValue("{$page}_page_button_size");
            $styles['color'] = $this->config->getValue("{$page}_page_button_color");
            $styles['shape'] = $this->config->getValue("{$page}_page_button_shape");
            $styles['label'] = $this->config->getValue("{$page}_page_button_label");

            $styles = $this->updateStyles($styles, $page);
        }
        return $styles;
    }

    /**
     * Update styles based on locale and labels
     *
     * @param array $styles
     * @param string $page
     * @return array
     */
    private function updateStyles(array $styles, string $page): array
    {
        $locale = $this->localeResolver->getLocale();

        $installmentPeriodLocale = [
            'en_MX' => 'mx',
            'es_MX' => 'mx',
            'en_BR' => 'br',
            'pt_BR' => 'br'
        ];

        // Credit label cannot be used with any custom color option or vertical layout.
        if ($styles['label'] === 'credit') {
            $styles['color'] = 'darkblue';
            $styles['layout'] = 'horizontal';
        }

        // Installment label is only available for specific locales
        if ($styles['label'] === 'installment') {
            if (array_key_exists($locale, $installmentPeriodLocale)) {
                $styles['installmentperiod'] = (int)$this->config->getValue(
                    $page .'_page_button_' . $installmentPeriodLocale[$locale] . '_installment_period'
                );
            } else {
                $styles['label'] = 'paypal';
            }
        }

        return $styles;
    }

    /**
     * Returns if is allowed PayPal Guest Checkout.
     *
     * @return bool
     */
    private function isPaypalGuestCheckoutAllowed(): bool
    {
        return $this->config->getValue('solution_type') === Config::EC_SOLUTION_TYPE_SOLE;
    }
}
