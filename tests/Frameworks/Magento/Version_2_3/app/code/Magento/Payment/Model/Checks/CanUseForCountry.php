<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Payment\Model\Checks;

use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Model\Quote;
use Magento\Payment\Model\Checks\CanUseForCountry\CountryProvider;

/**
 * Checks possibility to use payment method at particular country
 *
 * @api
 * @since 100.0.2
 */
class CanUseForCountry implements SpecificationInterface
{
    /**
     * @var CountryProvider
     */
    protected $countryProvider;

    /**
     * @param CountryProvider $countryProvider
     */
    public function __construct(CountryProvider $countryProvider)
    {
        $this->countryProvider = $countryProvider;
    }

    /**
     * Check whether payment method is applicable to quote
     * @param MethodInterface $paymentMethod
     * @param Quote $quote
     * @return bool
     */
    public function isApplicable(MethodInterface $paymentMethod, Quote $quote)
    {
        return $paymentMethod->canUseForCountry($this->countryProvider->getCountry($quote));
    }
}
