<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Setup;

use Magento\Framework\Locale\Bundle\CurrencyBundle;
use Magento\Framework\Locale\Bundle\LanguageBundle;
use Magento\Framework\Locale\Bundle\RegionBundle;
use Magento\Framework\Locale\ConfigInterface;
use Magento\Framework\Locale\Resolver;

/**
 * Retrieves lists of allowed locales and currencies
 */
class Lists
{
    /**
     * List of allowed locales
     *
     * @var array
     */
    protected $allowedLocales;

    /**
     * List of allowed currencies
     *
     * @var array
     */
    private $allowedCurrencies;

    /**
     * @param ConfigInterface $localeConfig
     */
    public function __construct(ConfigInterface $localeConfig)
    {
        $this->allowedLocales = $localeConfig->getAllowedLocales();
        $this->allowedCurrencies = $localeConfig->getAllowedCurrencies();
    }

    /**
     * Retrieve list of timezones
     *
     * @param bool $doSort
     * @return array
     */
    public function getTimezoneList($doSort = true)
    {
        $zones = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);
        $list = [];
        foreach ($zones as $code) {
            $list[$code] = \IntlTimeZone::createTimeZone($code)->getDisplayName(
                false,
                \IntlTimeZone::DISPLAY_LONG,
                Resolver::DEFAULT_LOCALE
            ) . ' (' . $code . ')';
        }

        if ($doSort) {
            asort($list);
        }

        return $list;
    }

    /**
     * Retrieve list of currencies
     *
     * @return array
     */
    public function getCurrencyList()
    {
        $currencies = (new CurrencyBundle())->get(Resolver::DEFAULT_LOCALE)['Currencies'];
        $list = [];
        foreach ($currencies as $code => $data) {
            $isAllowedCurrency = array_search($code, $this->allowedCurrencies) !== false;
            if (!$isAllowedCurrency) {
                continue;
            }
            $list[$code] = $data[1] . ' (' . $code . ')';
        }
        asort($list);
        return $list;
    }

    /**
     * Retrieve list of locales
     *
     * @return  array
     */
    public function getLocaleList()
    {
        $languages = (new LanguageBundle())->get(Resolver::DEFAULT_LOCALE)['Languages'];
        $countries = (new RegionBundle())->get(Resolver::DEFAULT_LOCALE)['Countries'];
        $locales = \ResourceBundle::getLocales('') ?: [];
        $allowedLocales = array_flip($this->allowedLocales);
        $list = [];
        foreach ($locales as $locale) {
            if (!isset($allowedLocales[$locale])) {
                continue;
            }
            $language = \Locale::getPrimaryLanguage($locale);
            $country = \Locale::getRegion($locale);
            $script = \Locale::getScript($locale);
            if (!$languages[$language] || !$countries[$country]) {
                continue;
            }
            if ($script !== '') {
                $script = \Locale::getDisplayScript($locale) . ', ';
            }
            $list[$locale] = $languages[$language] . ' (' . $script . $countries[$country] . ')';
        }
        asort($list);
        return $list;
    }
}
