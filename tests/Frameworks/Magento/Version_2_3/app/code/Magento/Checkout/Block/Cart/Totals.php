<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Checkout\Block\Cart;

use Magento\Framework\View\Element\BlockInterface;
use Magento\Checkout\Block\Checkout\LayoutProcessorInterface;
use Magento\Sales\Model\ConfigInterface;

/**
 * Totals cart block.
 *
 * @api
 * @since 100.0.2
 */
class Totals extends \Magento\Checkout\Block\Cart\AbstractCart
{
    /**
     * @var array
     */
    protected $_totalRenderers;

    /**
     * @var string
     */
    protected $_defaultRenderer = \Magento\Checkout\Block\Total\DefaultTotal::class;

    /**
     * @var array
     */
    protected $_totals = null;

    /**
     * @var \Magento\Sales\Model\Config
     */
    protected $_salesConfig;

    /**
     * @var LayoutProcessorInterface[]
     */
    protected $layoutProcessors;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param ConfigInterface $salesConfig
     * @param array $layoutProcessors
     * @param array $data
     * @codeCoverageIgnore
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        ConfigInterface $salesConfig,
        array $layoutProcessors = [],
        array $data = []
    ) {
        $this->_salesConfig = $salesConfig;
        parent::__construct($context, $customerSession, $checkoutSession, $data);
        $this->_isScopePrivate = true;
        $this->layoutProcessors = $layoutProcessors;
    }

    /**
     * Retrieve encoded js layout.
     *
     * @return string
     */
    public function getJsLayout()
    {
        foreach ($this->layoutProcessors as $processor) {
            $this->jsLayout = $processor->process($this->jsLayout);
        }

        return json_encode($this->jsLayout, JSON_HEX_TAG);
    }

    /**
     * Retrieve totals from cache.
     *
     * @return array
     */
    public function getTotals()
    {
        if ($this->_totals === null) {
            return parent::getTotals();
        }
        return $this->_totals;
    }

    /**
     * Set totals to cache.
     *
     * @param array $value
     * @return $this
     * @codeCoverageIgnore
     */
    public function setTotals($value)
    {
        $this->_totals = $value;
        return $this;
    }

    /**
     * Create totals block and set totals.
     *
     * @param string $code
     * @return BlockInterface
     */
    protected function _getTotalRenderer($code)
    {
        $blockName = $code . '_total_renderer';
        $block = $this->getLayout()->getBlock($blockName);
        if (!$block) {
            $renderer = $this->_salesConfig->getTotalsRenderer('quote', 'totals', $code);
            if (!empty($renderer)) {
                $block = $renderer;
            } else {
                $block = $this->_defaultRenderer;
            }

            $block = $this->getLayout()->createBlock($block, $blockName);
        }
        /**
         * Transfer totals to renderer
         */
        $block->setTotals($this->getTotals());
        return $block;
    }

    /**
     * Get totals html.
     *
     * @param mixed $total
     * @param int|null $area
     * @param int $colspan
     * @return string
     */
    public function renderTotal($total, $area = null, $colspan = 1)
    {
        $code = $total->getCode();
        if ($total->getAs()) {
            $code = $total->getAs();
        }
        return $this->_getTotalRenderer(
            $code
        )->setTotal(
            $total
        )->setColspan(
            $colspan
        )->setRenderingArea(
            $area === null ? -1 : $area
        )->toHtml();
    }

    /**
     * Render totals html for specific totals area (footer, body)
     *
     * @param   null|string $area
     * @param   int $colspan
     * @return  string
     */
    public function renderTotals($area = null, $colspan = 1)
    {
        $html = '';
        foreach ($this->getTotals() as $total) {
            if ($total->getArea() != $area && $area != -1) {
                continue;
            }
            $html .= $this->renderTotal($total, $area, $colspan);
        }
        return $html;
    }

    /**
     * Check if we have display grand total in base currency
     *
     * @return bool
     */
    public function needDisplayBaseGrandtotal()
    {
        $quote = $this->getQuote();
        if ($quote->getBaseCurrencyCode() != $quote->getQuoteCurrencyCode()) {
            return true;
        }
        return false;
    }

    /**
     * Get formatted in base currency base grand total value
     *
     * @return string
     */
    public function displayBaseGrandtotal()
    {
        $firstTotal = reset($this->_totals);
        if ($firstTotal) {
            $total = $firstTotal->getAddress()->getBaseGrandTotal();
            return $this->_storeManager->getStore()->getBaseCurrency()->format($total, [], true);
        }
        return '-';
    }

    /**
     * Get active or custom quote
     *
     * @return \Magento\Quote\Model\Quote
     */
    public function getQuote()
    {
        if ($this->getCustomQuote()) {
            return $this->getCustomQuote();
        }

        if (null === $this->_quote) {
            $this->_quote = $this->_checkoutSession->getQuote();
        }
        return $this->_quote;
    }
}
