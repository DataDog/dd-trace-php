<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Model\Product\Option\Type;

use Magento\Catalog\Api\Data\ProductCustomOptionInterface;

/**
 * Catalog product option date type
 *
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 */
class Date extends \Magento\Catalog\Model\Product\Option\Type\DefaultType
{
    /**
     * @var string
     */
    protected $_formattedOptionValue = null;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\TimezoneInterface
     */
    protected $_localeDate;

    /**
     * Serializer interface instance.
     *
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $serializer;

    /**
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate
     * @param array $data
     * @param \Magento\Framework\Serialize\Serializer\Json|null $serializer
     */
    public function __construct(
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        array $data = [],
        \Magento\Framework\Serialize\Serializer\Json $serializer = null
    ) {
        $this->_localeDate = $localeDate;
        $this->serializer = $serializer ?: \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Magento\Framework\Serialize\Serializer\Json::class);
        parent::__construct($checkoutSession, $scopeConfig, $data);
    }

    /**
     * Validate user input for option
     *
     * @param array $values All product option values, i.e. array (option_id => mixed, option_id => mixed...)
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function validateUserValue($values)
    {
        parent::validateUserValue($values);

        $option = $this->getOption();
        $value = $this->getUserValue();

        $dateValid = true;
        if ($this->_dateExists()) {
            if ($this->useCalendar()) {
                if (is_array($value) && $this->checkDateWithoutJSCalendar($value)) {
                    $value['date'] = sprintf("%s/%s/%s", $value['day'], $value['month'], $value['year']);
                }
                /* Fixed validation if the date was not saved correctly after re-saved the order
                for example: "09\/24\/2020,2020-09-24 00:00:00" */
                if (is_string($value) && preg_match('/^\d{1,4}.+\d{1,4}.+\d{1,4},+(\w|\W)*$/', $value)) {
                    $value = [
                        'date' => preg_replace('/,([^,]+),?$/', '', $value),
                    ];
                }
                $dateValid = isset($value['date']) && preg_match('/^\d{1,4}.+\d{1,4}.+\d{1,4}$/', $value['date']);
            } else {
                if (is_array($value)) {
                    $value = $this->prepareDateByDateInternal($value);
                }
                $dateValid = isset(
                    $value['day']
                ) && isset(
                    $value['month']
                ) && isset(
                    $value['year']
                ) && $value['day'] > 0 && $value['month'] > 0 && $value['year'] > 0;
            }
        }

        $timeValid = true;
        if ($this->_timeExists()) {
            $timeValid = isset(
                $value['hour']
            ) && isset(
                $value['minute']
            ) && is_numeric(
                $value['hour']
            ) && is_numeric(
                $value['minute']
            );
        }

        $isValid = $dateValid && $timeValid;

        if ($isValid) {
            $this->setUserValue(
                [
                    'date' => isset($value['date']) ? $value['date'] : '',
                    'year' => isset($value['year']) ? (int) $value['year'] : 0,
                    'month' => isset($value['month']) ? (int) $value['month'] : 0,
                    'day' => isset($value['day']) ? (int) $value['day'] : 0,
                    'hour' => isset($value['hour']) ? (int) $value['hour'] : 0,
                    'minute' => isset($value['minute']) ? (int) $value['minute'] : 0,
                    'day_part' => isset($value['day_part']) ? $value['day_part'] : '',
                    'date_internal' => isset($value['date_internal']) ? $value['date_internal'] : '',
                ]
            );
        } elseif (!$isValid && $option->getIsRequire() && !$this->getSkipCheckRequiredOption()) {
            $this->setIsValid(false);
            if (!$dateValid) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('Please specify date required option(s).')
                );
            } elseif (!$timeValid) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('Please specify time required option(s).')
                );
            } else {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __(
                        "The product's required option(s) weren't entered. "
                        . "Make sure the options are entered and try again."
                    )
                );
            }
        } else {
            $this->setUserValue(null);
        }

        return $this;
    }

    /**
     * Prepare option value for cart
     *
     * @return string|null Prepared option value
     * @throws \Magento\Framework\Exception\LocalizedException
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function prepareForCart()
    {
        if ($this->getIsValid() && $this->getUserValue() !== null) {
            $value = $this->getUserValue();

            if (isset($value['date_internal']) && $value['date_internal'] != '') {
                $this->_setInternalInRequest($value['date_internal']);
                return $value['date_internal'];
            }

            $timestamp = 0;

            if ($this->_dateExists()) {
                if ($this->useCalendar()) {
                    $timestamp += $this->_localeDate->date($value['date'], null, false, false)->getTimestamp();
                } else {
                    $timestamp += mktime(0, 0, 0, $value['month'], $value['day'], $value['year']);
                }
            } else {
                $timestamp += mktime(0, 0, 0, date('m'), date('d'), date('Y'));
            }

            if ($this->_timeExists()) {
                // 24hr hour conversion
                if (!$this->is24hTimeFormat()) {
                    $pmDayPart = 'pm' == strtolower($value['day_part']);
                    if (12 == $value['hour']) {
                        $value['hour'] = $pmDayPart ? 12 : 0;
                    } elseif ($pmDayPart) {
                        $value['hour'] += 12;
                    }
                }

                $timestamp += 60 * 60 * $value['hour'] + 60 * $value['minute'];
            }

            $date = (new \DateTime())->setTimestamp($timestamp);
            $result = $date->format('Y-m-d H:i:s');

            $originDate = (isset($value['date']) && $value['date'] != '') ? $value['date'] : null;

            // Save date in internal format to avoid locale date bugs
            $this->_setInternalInRequest($result, $originDate);

            return $result;
        } else {
            return null;
        }
    }

    /**
     * Return formatted option value for quote option
     *
     * @param string $optionValue Prepared for cart option value
     * @return string
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function getFormattedOptionValue($optionValue)
    {
        if ($this->_formattedOptionValue === null) {
            switch ($this->getOption()->getType()) {
                case ProductCustomOptionInterface::OPTION_TYPE_DATE:
                    $result = $this->_localeDate->formatDateTime(
                        new \DateTime($optionValue),
                        \IntlDateFormatter::MEDIUM,
                        \IntlDateFormatter::NONE,
                        null,
                        'UTC'
                    );
                    break;
                case ProductCustomOptionInterface::OPTION_TYPE_DATE_TIME:
                    $result = $this->_localeDate->formatDateTime(
                        new \DateTime($optionValue),
                        \IntlDateFormatter::SHORT,
                        \IntlDateFormatter::SHORT,
                        null,
                        'UTC'
                    );
                    break;
                case ProductCustomOptionInterface::OPTION_TYPE_TIME:
                    $result = $this->_localeDate->formatDateTime(
                        new \DateTime($optionValue),
                        \IntlDateFormatter::NONE,
                        \IntlDateFormatter::SHORT,
                        null,
                        'UTC'
                    );
                    break;
                default:
                    $result = $optionValue;
            }
            $this->_formattedOptionValue = $result;
        }
        return $this->_formattedOptionValue;
    }

    /**
     * Return printable option value
     *
     * @param string $optionValue Prepared for cart option value
     * @return string
     */
    public function getPrintableOptionValue($optionValue)
    {
        return $this->getFormattedOptionValue($optionValue);
    }

    /**
     * Return formatted option value ready to edit, ready to parse
     *
     * @param string $optionValue Prepared for cart option value
     * @return string
     */
    public function getEditableOptionValue($optionValue)
    {
        return $this->getFormattedOptionValue($optionValue);
    }

    /**
     * Parse user input value and return cart prepared value
     *
     * @param string $optionValue
     * @param array $productOptionValues Values for product option
     * @return string|null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function parseOptionValue($optionValue, $productOptionValues)
    {
        try {
            $date = new \DateTime($optionValue);
        } catch (\Exception $e) {
            return null;
        }
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Prepare option value for info buy request
     *
     * @param string $optionValue
     * @return array
     */
    public function prepareOptionValueForRequest($optionValue)
    {
        $confItem = $this->getConfigurationItem();
        $infoBuyRequest = $confItem->getOptionByCode('info_buyRequest');
        try {
            $value = $this->serializer->unserialize($infoBuyRequest->getValue());

            if (is_array($value) && isset($value['options'][$this->getOption()->getId()])) {
                return $value['options'][$this->getOption()->getId()];
            } else {
                return ['date_internal' => $optionValue];
            }
        } catch (\Exception $e) {
            return ['date_internal' => $optionValue];
        }
    }

    /**
     * Use Calendar on frontend or not
     *
     * @return boolean
     */
    public function useCalendar()
    {
        return (bool)$this->getConfigData('use_calendar');
    }

    /**
     * Time Format
     *
     * @return boolean
     */
    public function is24hTimeFormat()
    {
        return (bool)($this->getConfigData('time_format') == '24h');
    }

    /**
     * Year range start
     *
     * @return string|false
     */
    public function getYearStart()
    {
        $_range = $this->getConfigData('year_range') !== null
            ? explode(',', $this->getConfigData('year_range'))
            : [];
        return (isset($_range[0]) && !empty($_range[0])) ? $_range[0] : date('Y');
    }

    /**
     * Year range end
     *
     * @return string|false
     */
    public function getYearEnd()
    {
        $_range = $this->getConfigData('year_range') !== null
            ? explode(',', $this->getConfigData('year_range'))
            : [];
        return (isset($_range[1]) && !empty($_range[1])) ? $_range[1] : date('Y');
    }

    /**
     * Save internal value of option in infoBuy_request
     *
     * @param string $internalValue Datetime value in internal format
     * @param string|null $originDate date value in origin format
     * @return void
     */
    protected function _setInternalInRequest($internalValue, $originDate = null)
    {
        $requestOptions = $this->getRequest()->getOptions();
        if (!isset($requestOptions[$this->getOption()->getId()])) {
            $requestOptions[$this->getOption()->getId()] = [];
        }
        if (!is_array($requestOptions[$this->getOption()->getId()])) {
            $requestOptions[$this->getOption()->getId()] = [];
        }
        $requestOptions[$this->getOption()->getId()]['date_internal'] = $internalValue;
        if ($originDate) {
            $requestOptions[$this->getOption()->getId()]['date'] = $originDate;
        }
        $this->getRequest()->setOptions($requestOptions);
    }

    /**
     * Does option have date?
     *
     * @return boolean
     */
    protected function _dateExists()
    {
        return in_array(
            $this->getOption()->getType(),
            [
                ProductCustomOptionInterface::OPTION_TYPE_DATE,
                ProductCustomOptionInterface::OPTION_TYPE_DATE_TIME
            ]
        );
    }

    /**
     * Does option have time?
     *
     * @return boolean
     */
    protected function _timeExists()
    {
        return in_array(
            $this->getOption()->getType(),
            [
                ProductCustomOptionInterface::OPTION_TYPE_DATE_TIME,
                ProductCustomOptionInterface::OPTION_TYPE_TIME
            ]
        );
    }

    /**
     * Check is date without JS Calendar
     *
     * @param array $value
     *
     * @return bool
     */
    private function checkDateWithoutJSCalendar(array $value): bool
    {
        return empty($value['date'])
            && !empty($value['day'])
            && !empty($value['month'])
            && !empty($value['year']);
    }

    /**
     * Prepare date by date internal
     *
     * @param array $value
     * @return array
     */
    private function prepareDateByDateInternal(array $value): array
    {
        if (!empty($value['date']) && !empty($value['date_internal'])) {
            $formatDate = explode(' ', $value['date_internal']);
            $date = explode('-', $formatDate[0]);
            $value['year'] = $date[0];
            $value['month'] = $date[1];
            $value['day'] = $date[2];
        }

        return $value;
    }
}
