<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Stdlib\DateTime\Filter;

/**
 * Date/Time filter. Converts datetime from localized to internal format.
 *
 * @api
 * @since 100.0.2
 */
class DateTime extends Date
{
    /**
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate
     *
     */
    public function __construct(\Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate)
    {
        parent::__construct($localeDate);
        $this->_localToNormalFilter = new \Zend_Filter_LocalizedToNormalized(
            [
                'date_format' => $this->_localeDate->getDateTimeFormat(
                    \IntlDateFormatter::SHORT
                ),
            ]
        );
        $this->_normalToLocalFilter = new \Zend_Filter_NormalizedToLocalized(
            ['date_format' => \Magento\Framework\Stdlib\DateTime::DATETIME_INTERNAL_FORMAT]
        );
    }

    /**
     * Convert date from localized to internal format
     *
     * @param string $value
     * @return string
     * @throws \Exception
     * @since 100.1.0
     */
    public function filter($value)
    {
        try {
            $dateTime = $this->_localeDate->date($value, null, false);
            return $dateTime->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            throw new \Exception("Invalid input datetime format of value '$value'", $e->getCode(), $e);
        }
    }
}
