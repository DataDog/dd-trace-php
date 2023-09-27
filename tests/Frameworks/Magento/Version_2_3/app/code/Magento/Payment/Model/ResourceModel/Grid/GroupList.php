<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Payment\Model\ResourceModel\Grid;

/**
 * Sales transaction types option array
 */
class GroupList implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Payment data
     *
     * @var \Magento\Payment\Helper\Data
     */
    protected $paymentData;

    /**
     * @param \Magento\Payment\Helper\Data $paymentData
     */
    public function __construct(\Magento\Payment\Helper\Data $paymentData)
    {
        $this->paymentData = $paymentData;
    }

    /**
     * Return option array
     *
     * @return array
     */
    public function toOptionArray()
    {
        return $this->paymentData->getPaymentMethodList(true, true, true);
    }
}
