<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Block\Adminhtml\Product\Edit;

use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Tax\Model\TaxClass\Source\Product as ProductTaxClassSource;

class Js extends \Magento\Backend\Block\Template
{
    /**
     * Core registry
     *
     * @var \Magento\Framework\Registry
     */
    protected $coreRegistry = null;

    /**
     * @var TaxCalculationInterface
     */
    protected $calculationService;

    /**
     * @var ProductTaxClassSource
     */
    protected $productTaxClassSource;

    /**
     * Current customer
     *
     * @var CurrentCustomer
     */
    protected $currentCustomer;

    /**
     * Json helper
     *
     * @var \Magento\Framework\Json\Helper\Data
     */
    protected $jsonHelper;

    /**
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param CurrentCustomer $currentCustomer
     * @param \Magento\Framework\Json\Helper\Data $jsonHelper
     * @param TaxCalculationInterface $calculationService
     * @param ProductTaxClassSource $productTaxClassSource
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Registry $registry,
        CurrentCustomer $currentCustomer,
        \Magento\Framework\Json\Helper\Data $jsonHelper,
        TaxCalculationInterface $calculationService,
        ProductTaxClassSource $productTaxClassSource,
        array $data = []
    ) {
        $this->coreRegistry = $registry;
        $this->currentCustomer = $currentCustomer;
        $this->jsonHelper = $jsonHelper;
        $this->calculationService = $calculationService;
        $this->productTaxClassSource = $productTaxClassSource;
        parent::__construct($context, $data);
    }

    /**
     * Get currently edited product
     *
     * @return \Magento\Catalog\Model\Product
     */
    public function getProduct()
    {
        return $this->coreRegistry->registry('current_product');
    }

    /**
     * Get store object of currently edited product
     *
     * @return \Magento\Store\Model\Store
     */
    public function getStore()
    {
        $product = $this->getProduct();
        if ($product) {
            return $this->_storeManager->getStore($product->getStoreId());
        }
        return $this->_storeManager->getStore();
    }

    /**
     * Get all tax rates JSON for all product tax classes.
     *
     * @return string
     */
    public function getAllRatesByProductClassJson()
    {
        $result = [];
        foreach ($this->productTaxClassSource->getAllOptions() as $productTaxClass) {
            $taxClassId = $productTaxClass['value'];
            $taxRate = $this->calculationService->getDefaultCalculatedRate(
                $taxClassId,
                $this->currentCustomer->getCustomerId(),
                $this->getStore()->getId()
            );
            $result["value_{$taxClassId}"] = $taxRate;
        }
        return $this->jsonHelper->jsonEncode($result);
    }
}
