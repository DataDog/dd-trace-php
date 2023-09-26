<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Tax totals modification block. Can be used just as subblock of \Magento\Sales\Block\Order\Totals
 */
namespace Magento\Tax\Block\Sales\Order;

use Magento\Sales\Model\Order;

/**
 *  Tax totals modification block.
 *
 * @api
 * @since 100.0.2
 */
class Tax extends \Magento\Framework\View\Element\Template
{
    /**
     * Tax configuration model
     *
     * @var \Magento\Tax\Model\Config
     */
    protected $_config;

    /**
     * @var Order
     */
    protected $_order;

    /**
     * @var \Magento\Framework\DataObject
     */
    protected $_source;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Tax\Model\Config $taxConfig
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Tax\Model\Config $taxConfig,
        array $data = []
    ) {
        $this->_config = $taxConfig;
        parent::__construct($context, $data);
    }

    /**
     * Check if we nedd display full tax total info
     *
     * @return bool
     */
    public function displayFullSummary()
    {
        return $this->_config->displaySalesFullSummary($this->getOrder()->getStore());
    }

    /**
     * Get data (totals) source model
     *
     * @return \Magento\Framework\DataObject
     */
    public function getSource()
    {
        return $this->_source;
    }

    /**
     * Initialize all order totals relates with tax
     *
     * @return \Magento\Tax\Block\Sales\Order\Tax
     */
    public function initTotals()
    {
        /** @var $parent \Magento\Sales\Block\Adminhtml\Order\Invoice\Totals */
        $parent = $this->getParentBlock();
        $this->_order = $parent->getOrder();
        $this->_source = $parent->getSource();

        $store = $this->_order->getStore();
        $allowTax = $this->_source->getTaxAmount() > 0 || $this->_config->displaySalesZeroTax($store);
        $grandTotal = (double)$this->_source->getGrandTotal();
        if (!$grandTotal || $allowTax && !$this->_config->displaySalesTaxWithGrandTotal($store)) {
            $this->_addTax();
        }

        $this->_initSubtotal();
        $this->_initShipping();
        $this->_initDiscount();
        $this->_initGrandTotal();
        return $this;
    }

    /**
     * Add tax total string
     *
     * @param string $after
     * @return \Magento\Tax\Block\Sales\Order\Tax
     */
    protected function _addTax($after = 'discount')
    {
        $taxTotal = new \Magento\Framework\DataObject(['code' => 'tax', 'block_name' => $this->getNameInLayout()]);
        $totals = $this->getParentBlock()->getTotals();
        if (isset($totals['grand_total_incl'])) {
            $this->getParentBlock()->addTotal($taxTotal, 'grand_total');
        }
        $this->getParentBlock()->addTotal($taxTotal, $after);
        return $this;
    }

    /**
     * Get order store object
     *
     * @return \Magento\Store\Model\Store
     */
    public function getStore()
    {
        return $this->_order->getStore();
    }

    /**
     * Initialization grand total.
     *
     * @return $this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function _initSubtotal()
    {
        $store = $this->_order->getStore();
        $parent = $this->getParentBlock();
        $subtotal = $parent->getTotal('subtotal');
        if (!$subtotal) {
            return $this;
        }
        if ($this->_config->displaySalesSubtotalBoth($store)) {
            $subtotal = (double)$this->_source->getSubtotal();
            $baseSubtotal = (double)$this->_source->getBaseSubtotal();
            $subtotalIncl = (double)$this->_source->getSubtotalInclTax();
            $baseSubtotalIncl = (double)$this->_source->getBaseSubtotalInclTax();

            if (!$subtotalIncl || !$baseSubtotalIncl) {
                // Calculate the subtotal if it is not set
                $subtotalIncl = $subtotal
                    + $this->_source->getTaxAmount()
                    - $this->_source->getShippingTaxAmount();
                $baseSubtotalIncl = $baseSubtotal
                    + $this->_source->getBaseTaxAmount()
                    - $this->_source->getBaseShippingTaxAmount();

                if ($this->_source instanceof Order) {
                    // Adjust for the discount tax compensation
                    foreach ($this->_source->getAllItems() as $item) {
                        $subtotalIncl += $item->getDiscountTaxCompensationAmount();
                        $baseSubtotalIncl += $item->getBaseDiscountTaxCompensationAmount();
                    }
                }
            }

            $subtotalIncl = max(0, $subtotalIncl);
            $baseSubtotalIncl = max(0, $baseSubtotalIncl);
            $totalExcl = new \Magento\Framework\DataObject(
                [
                    'code' => 'subtotal_excl',
                    'value' => $subtotal,
                    'base_value' => $baseSubtotal,
                    'label' => __('Subtotal (Excl.Tax)'),
                ]
            );
            $totalIncl = new \Magento\Framework\DataObject(
                [
                    'code' => 'subtotal_incl',
                    'value' => $subtotalIncl,
                    'base_value' => $baseSubtotalIncl,
                    'label' => __('Subtotal (Incl.Tax)'),
                ]
            );
            $parent->addTotal($totalExcl, 'subtotal');
            $parent->addTotal($totalIncl, 'subtotal_excl');
            $parent->removeTotal('subtotal');
        } elseif ($this->_config->displaySalesSubtotalInclTax($store)) {
            $subtotalIncl = (double)$this->_source->getSubtotalInclTax();
            $baseSubtotalIncl = (double)$this->_source->getBaseSubtotalInclTax();

            if (!$subtotalIncl) {
                $subtotalIncl = $this->_source->getSubtotal() +
                    $this->_source->getTaxAmount() -
                    $this->_source->getShippingTaxAmount();
            }
            if (!$baseSubtotalIncl) {
                $baseSubtotalIncl = $this->_source->getBaseSubtotal() +
                    $this->_source->getBaseTaxAmount() -
                    $this->_source->getBaseShippingTaxAmount();
            }

            $total = $parent->getTotal('subtotal');
            if ($total) {
                $total->setValue(max(0, $subtotalIncl));
                $total->setBaseValue(max(0, $baseSubtotalIncl));
            }
        }
        return $this;
    }

    /**
     * Init shipping.
     *
     * @return $this
     */
    protected function _initShipping()
    {
        $store = $this->_order->getStore();
        /** @var \Magento\Sales\Block\Order\Totals $parent */
        $parent = $this->getParentBlock();
        $shipping = $parent->getTotal('shipping');
        if (!$shipping) {
            return $this;
        }

        if ($this->_config->displaySalesShippingBoth($store)) {
            $shipping = (double)$this->_source->getShippingAmount();
            $baseShipping = (double)$this->_source->getBaseShippingAmount();
            $shippingIncl = (double)$this->_source->getShippingInclTax();
            if (!$shippingIncl) {
                $shippingIncl = $shipping + (double)$this->_source->getShippingTaxAmount();
            }
            $baseShippingIncl = (double)$this->_source->getBaseShippingInclTax();
            if (!$baseShippingIncl) {
                $baseShippingIncl = $baseShipping + (double)$this->_source->getBaseShippingTaxAmount();
            }

            $couponDescription = $this->getCouponDescription();

            $totalExcl = new \Magento\Framework\DataObject(
                [
                    'code' => 'shipping',
                    'value' => $shipping,
                    'base_value' => $baseShipping,
                    'label' => __('Shipping & Handling (Excl.Tax)') . $couponDescription,
                ]
            );
            $totalIncl = new \Magento\Framework\DataObject(
                [
                    'code' => 'shipping_incl',
                    'value' => $shippingIncl,
                    'base_value' => $baseShippingIncl,
                    'label' => __('Shipping & Handling (Incl.Tax)') . $couponDescription,
                ]
            );
            $parent->addTotal($totalExcl, 'shipping');
            $parent->addTotal($totalIncl, 'shipping');
        } elseif ($this->_config->displaySalesShippingInclTax($store)) {
            $shippingIncl = $this->_source->getShippingInclTax();
            if (!$shippingIncl) {
                $shippingIncl = $this->_source->getShippingAmount() + $this->_source->getShippingTaxAmount();
            }
            $baseShippingIncl = $this->_source->getBaseShippingInclTax();
            if (!$baseShippingIncl) {
                $baseShippingIncl = $this->_source->getBaseShippingAmount() +
                    $this->_source->getBaseShippingTaxAmount();
            }
            $total = $parent->getTotal('shipping');
            if ($total) {
                $total->setValue($shippingIncl);
                $total->setBaseValue($baseShippingIncl);
            }
        }
        return $this;
    }

    /**
     * Init discount.
     *
     * phpcs:disable Magento2.CodeAnalysis.EmptyBlock
     *
     * @return void
     */
    protected function _initDiscount()
    {
    }
    //phpcs:enable
    /**
     * Init grand total.
     *
     * @return $this
     */
    protected function _initGrandTotal()
    {
        $store = $this->_order->getStore();
        $parent = $this->getParentBlock();
        $grandototal = $parent->getTotal('grand_total');
        if (!$grandototal || !(double)$this->_source->getGrandTotal()) {
            return $this;
        }

        if ($this->_config->displaySalesTaxWithGrandTotal($store)) {
            $grandtotal = $this->_source->getGrandTotal();
            $baseGrandtotal = $this->_source->getBaseGrandTotal();
            $grandtotalExcl = $grandtotal - $this->_source->getTaxAmount();
            $baseGrandtotalExcl = $baseGrandtotal - $this->_source->getBaseTaxAmount();
            $grandtotalExcl = max($grandtotalExcl, 0);
            $baseGrandtotalExcl = max($baseGrandtotalExcl, 0);
            $totalExcl = new \Magento\Framework\DataObject(
                [
                    'code' => 'grand_total',
                    'strong' => true,
                    'value' => $grandtotalExcl,
                    'base_value' => $baseGrandtotalExcl,
                    'label' => __('Grand Total (Excl.Tax)'),
                ]
            );
            $totalIncl = new \Magento\Framework\DataObject(
                [
                    'code' => 'grand_total_incl',
                    'strong' => true,
                    'value' => $grandtotal,
                    'base_value' => $baseGrandtotal,
                    'label' => __('Grand Total (Incl.Tax)'),
                ]
            );
            $parent->addTotal($totalIncl, 'grand_total');
            $parent->addTotal($totalExcl, 'tax');
            $this->_addTax('grand_total');
        }
        return $this;
    }

    /**
     * Return order.
     *
     * @return Order
     */
    public function getOrder()
    {
        return $this->_order;
    }

    /**
     * Return label properties.
     *
     * @return array
     */
    public function getLabelProperties()
    {
        return $this->getParentBlock()->getLabelProperties();
    }

    /**
     * Retuen value properties.
     *
     * @return array
     */
    public function getValueProperties()
    {
        return $this->getParentBlock()->getValueProperties();
    }

    /**
     * Returns additional information about coupon code if it is not displayed in totals.
     *
     * @return string
     */
    private function getCouponDescription(): string
    {
        $couponDescription = "";

        /** @var \Magento\Sales\Block\Order\Totals $parent */
        $parent = $this->getParentBlock();
        $couponCode = $parent->getSource()
            ->getCouponCode();

        if ($couponCode && !$parent->getTotal('discount')) {
            $couponDescription = " ({$couponCode})";
        }

        return $couponDescription;
    }
}
