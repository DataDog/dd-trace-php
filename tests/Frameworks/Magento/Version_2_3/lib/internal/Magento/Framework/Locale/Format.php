<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Locale;

/**
 * Price locale format.
 */
class Format implements \Magento\Framework\Locale\FormatInterface
{
    /**
     * Japan locale code
     */
    private const JAPAN_LOCALE_CODE = 'ja_JP';

    /**
     * Arab locale code
     */
    private const ARABIC_LOCALE_CODES = [
        'ar_DZ',
        'ar_EG',
        'ar_KW',
        'ar_MA',
        'ar_SA',
    ];

    /**
     * @var \Magento\Framework\App\ScopeResolverInterface
     */
    protected $_scopeResolver;

    /**
     * @var \Magento\Framework\Locale\ResolverInterface
     */
    protected $_localeResolver;

    /**
     * @var \Magento\Directory\Model\CurrencyFactory
     */
    protected $currencyFactory;

    /**
     * @param \Magento\Framework\App\ScopeResolverInterface $scopeResolver
     * @param ResolverInterface $localeResolver
     * @param \Magento\Directory\Model\CurrencyFactory $currencyFactory
     */
    public function __construct(
        \Magento\Framework\App\ScopeResolverInterface $scopeResolver,
        \Magento\Framework\Locale\ResolverInterface $localeResolver,
        \Magento\Directory\Model\CurrencyFactory $currencyFactory
    ) {
        $this->_scopeResolver = $scopeResolver;
        $this->_localeResolver = $localeResolver;
        $this->currencyFactory = $currencyFactory;
    }

    /**
     * Returns the first found number from a string.
     *
     * Parsing depends on given locale (grouping and decimal)
     *
     * Examples for input:
     * '  2345.4356,1234' = 23455456.1234
     * '+23,3452.123' = 233452.123
     * ' 12343 ' = 12343
     * '-9456km' = -9456
     * '0' = 0
     * '2 054,10' = 2054.1
     * '2'054.52' = 2054.52
     * '2,46 GB' = 2.46
     *
     * @param string|float|int $value
     * @return float|null
     */
    public function getNumber($value)
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return (float)$value;
        }

        /** Normalize for Arabic locale */
        if (in_array($this->_localeResolver->getLocale(), self::ARABIC_LOCALE_CODES)) {
            $value = $this->normalizeArabicLocale($value);
        }

        //trim spaces and apostrophes
        $value = preg_replace('/[^0-9^\^.,-]/m', '', $value);

        $separatorComa = strpos($value, ',');
        $separatorDot = strpos($value, '.');

        if ($separatorComa !== false && $separatorDot !== false) {
            if ($separatorComa > $separatorDot) {
                $value = str_replace(['.', ','], ['', '.'], $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($separatorComa !== false) {
            $locale = $this->_localeResolver->getLocale();
            /**
             * It's hard code for Japan locale.
             * Comma separator uses as group separator: 4,000 saves as 4,000.00
             */
            $value = str_replace(
                ',',
                $locale === self::JAPAN_LOCALE_CODE ? '' : '.',
                $value
            );
        }

        return (float)$value;
    }

    /**
     * Returns an array with price formatting info
     *
     * @param string $localeCode Locale code.
     * @param string $currencyCode Currency code.
     * @return array
     */
    public function getPriceFormat($localeCode = null, $currencyCode = null)
    {
        $localeCode = $localeCode ?: $this->_localeResolver->getLocale();
        if ($currencyCode) {
            $currency = $this->currencyFactory->create()->load($currencyCode);
        } else {
            $currency = $this->_scopeResolver->getScope()->getCurrentCurrency();
        }

        $formatter = new \NumberFormatter(
            $currency->getCode() ? $localeCode . '@currency=' . $currency->getCode() : $localeCode,
            \NumberFormatter::CURRENCY
        );
        $format = $formatter->getPattern();
        $decimalSymbol = $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
        $groupSymbol = $formatter->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL);

        $pos = strpos($format, ';');
        if ($pos !== false) {
            $format = substr($format, 0, $pos);
        }
        $format = preg_replace("/[^0\#\.,]/", '', $format);
        $totalPrecision = 0;
        $decimalPoint = strpos($format, '.');
        if ($decimalPoint !== false) {
            $totalPrecision = strlen($format) - (strrpos($format, '.') + 1);
        } else {
            $decimalPoint = strlen($format);
        }
        $requiredPrecision = $totalPrecision;
        $t = substr($format, $decimalPoint);
        $pos = strpos($t, '#');
        if ($pos !== false) {
            $requiredPrecision = strlen($t) - $pos - $totalPrecision;
        }

        if (strrpos($format, ',') !== false) {
            $group = $decimalPoint - strrpos($format, ',') - 1;
        } else {
            $group = strrpos($format, '.');
        }

        $result = [
            //TODO: change interface
            'pattern' => $currency->getOutputFormat(),
            'precision' => $totalPrecision,
            'requiredPrecision' => $requiredPrecision,
            'decimalSymbol' => $decimalSymbol,
            'groupSymbol' => $groupSymbol,
            'groupLength' => $group,
            'integerRequired' => $totalPrecision == 0,
        ];

        return $result;
    }

    /**
     * Normalizes the number of Arabic locale.
     *
     * Substitutes Arabic thousands grouping and Arabic decimal separator symbols (U+066C, U+066B)
     * with common grouping and decimal separator
     *
     * @param string $value
     * @return string
     */
    private function normalizeArabicLocale($value): string
    {
        $arabicGroupSymbol = '٬';
        $arabicDecimalSymbol = '٫';
        $value = str_replace([$arabicGroupSymbol, $arabicDecimalSymbol], [',', '.'], $value);

        return $value;
    }
}
