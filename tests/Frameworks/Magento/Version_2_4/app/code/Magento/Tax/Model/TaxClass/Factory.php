<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Tax Class factory
 */
namespace Magento\Tax\Model\TaxClass;

class Factory
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager;

    /**
     * Type to class map
     *
     * @var array
     */
    protected $_types = [
        \Magento\Tax\Model\ClassModel::TAX_CLASS_TYPE_CUSTOMER => \Magento\Tax\Model\TaxClass\Type\Customer::class,
        \Magento\Tax\Model\ClassModel::TAX_CLASS_TYPE_PRODUCT => \Magento\Tax\Model\TaxClass\Type\Product::class,
    ];

    /**
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     */
    public function __construct(\Magento\Framework\ObjectManagerInterface $objectManager)
    {
        $this->_objectManager = $objectManager;
    }

    /**
     * Create new config object
     *
     * @param \Magento\Tax\Model\ClassModel $taxClass
     * @return \Magento\Tax\Model\TaxClass\Type\TypeInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function create(\Magento\Tax\Model\ClassModel $taxClass)
    {
        $taxClassType = $taxClass->getClassType();
        if (!array_key_exists($taxClassType, $this->_types)) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Invalid type of tax class "%1"', $taxClassType)
            );
        }
        return $this->_objectManager->create(
            $this->_types[$taxClassType],
            ['data' => ['id' => $taxClass->getId()]]
        );
    }
}
