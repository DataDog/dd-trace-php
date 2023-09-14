<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Backend\Block\Widget\Grid\Column\Filter;

use Magento\Framework\Stdlib\DateTime\DateTimeFormatterInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

/**
 * Date grid column filter
 * @api
 * @since 100.0.2
 */
class Date extends \Magento\Backend\Block\Widget\Grid\Column\Filter\AbstractFilter
{
    /**
     * @var \Magento\Framework\Math\Random
     */
    protected $mathRandom;

    /**
     * @var \Magento\Framework\Locale\ResolverInterface
     */
    protected $localeResolver;

    /**
     * @var DateTimeFormatterInterface
     */
    protected $dateTimeFormatter;

    /**
     * @var SecureHtmlRenderer
     */
    protected $secureHtmlRenderer;

    /**
     * @param \Magento\Backend\Block\Context $context
     * @param \Magento\Framework\DB\Helper $resourceHelper
     * @param \Magento\Framework\Math\Random $mathRandom
     * @param \Magento\Framework\Locale\ResolverInterface $localeResolver
     * @param DateTimeFormatterInterface $dateTimeFormatter
     * @param array $data
     * @param SecureHtmlRenderer|null $secureHtmlRenderer
     */
    public function __construct(
        \Magento\Backend\Block\Context $context,
        \Magento\Framework\DB\Helper $resourceHelper,
        \Magento\Framework\Math\Random $mathRandom,
        \Magento\Framework\Locale\ResolverInterface $localeResolver,
        DateTimeFormatterInterface $dateTimeFormatter,
        array $data = [],
        ?SecureHtmlRenderer $secureHtmlRenderer = null
    ) {
        $this->mathRandom = $mathRandom;
        $this->localeResolver = $localeResolver;
        parent::__construct($context, $resourceHelper, $data);
        $this->dateTimeFormatter = $dateTimeFormatter;
        $this->secureHtmlRenderer = $secureHtmlRenderer ?? ObjectManager::getInstance()->get(SecureHtmlRenderer::class);
    }

    /**
     * @inheritDoc
     */
    public function getHtml()
    {
        $htmlId = $this->mathRandom->getUniqueHash($this->_getHtmlId());
        $format = $this->_localeDate->getDateFormat(\IntlDateFormatter::SHORT);
        $html = '<div class="range" id="' .
            $htmlId .
            '_range"><div class="range-line date">' .
            '<input type="text" name="' .
            $this->_getHtmlName() .
            '[from]" id="' .
            $htmlId .
            '_from"' .
            ' value="' .
            $this->getEscapedValue(
                'from'
            ) . '" class="admin__control-text input-text no-changes" placeholder="' . __(
                'From'
            ) . '" ' . $this->getUiId(
                'filter',
                $this->_getHtmlName(),
                'from'
            ) . '/>' . '</div>';
        $html .= '<div class="range-line date">' .
            '<input type="text" name="' .
            $this->_getHtmlName() .
            '[to]" id="' .
            $htmlId .
            '_to"' .
            ' value="' .
            $this->getEscapedValue(
                'to'
            ) . '" class="input-text admin__control-text no-changes" placeholder="' . __(
                'To'
            ) . '" ' . $this->getUiId(
                'filter',
                $this->_getHtmlName(),
                'to'
            ) . '/>' . '</div></div>';
        $html .= '<input type="hidden" name="' .
            $this->_getHtmlName() .
            '[locale]"' .
            ' value="' .
            $this->localeResolver->getLocale() .
            '"/>';
        $scriptString = '
            require(["jquery", "mage/calendar"], function($){
                $("#' .
            $htmlId .
            '_range").dateRange({
                    dateFormat: "' .
            $format .
            '",
                        buttonText: "' . $this->escapeHtml(__('Date selector')) .
            '",
                    from: {
                        id: "' .
            $htmlId .
            '_from"
                    },
                    to: {
                        id: "' .
            $htmlId .
            '_to"
                    }
                })
            });';
        $html .= $this->secureHtmlRenderer->renderTag('script', [], $scriptString, false);

        return $html;
    }

    /**
     * Return escaped value.
     *
     * @param string|null $index
     * @return array|string|int|float|null
     */
    public function getEscapedValue($index = null)
    {
        $value = $this->getValue($index);
        if ($value instanceof \DateTimeInterface) {
            return $this->dateTimeFormatter->formatObject(
                $value,
                $this->_localeDate->getDateFormat(\IntlDateFormatter::SHORT)
            );
        }

        if (is_string($value)) {
            return $this->escapeHtml($value);
        }

        return $value;
    }

    /**
     * Return value.
     *
     * @param string|null $index
     * @return array|string|int|float|null
     */
    public function getValue($index = null)
    {
        if ($index) {
            if ($data = $this->getData('value', 'orig_' . $index)) {
                return $data;
            }
            return null;
        }
        $value = $this->getData('value');
        if (is_array($value)) {
            $value['date'] = true;
        }
        return $value;
    }

    /**
     * Return conditions.
     *
     * @return array|string|int|float|null
     */
    public function getCondition()
    {
        $value = $this->getValue();

        return $value;
    }

    /**
     * Set value.
     *
     * @param array|string|int|float $value
     * @return $this
     */
    public function setValue($value)
    {
        if (isset($value['locale'])) {
            if (!empty($value['from'])) {
                $value['orig_from'] = $value['from'];
                $value['from'] = $this->_convertDate($value['from']);
            }
            if (!empty($value['to'])) {
                $value['orig_to'] = $value['to'];
                $value['to'] = $this->_convertDate($value['to']);
            }
        }
        if (empty($value['from']) && empty($value['to'])) {
            $value = null;
        }
        $this->setData('value', $value);
        return $this;
    }

    /**
     * Convert given date to default (UTC) timezone
     *
     * @param string $date
     * @return \DateTime|null
     */
    protected function _convertDate($date)
    {
        $timezone = $this->getColumn()->getTimezone() !== false ? $this->_localeDate->getConfigTimezone() : 'UTC';
        $adminTimeZone = new \DateTimeZone($timezone);
        $formatter = new \IntlDateFormatter(
            $this->localeResolver->getLocale(),
            \IntlDateFormatter::SHORT,
            \IntlDateFormatter::NONE,
            $adminTimeZone
        );
        $simpleRes = new \DateTime('now', $adminTimeZone);
        $simpleRes->setTimestamp($formatter->parse($date));
        $simpleRes->setTime(0, 0, 0);
        $simpleRes->setTimezone(new \DateTimeZone('UTC'));
        return $simpleRes;
    }
}
