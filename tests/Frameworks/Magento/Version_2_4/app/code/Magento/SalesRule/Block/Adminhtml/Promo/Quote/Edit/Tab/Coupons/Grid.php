<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\SalesRule\Block\Adminhtml\Promo\Quote\Edit\Tab\Coupons;

/**
 * Coupon codes grid
 *
 * @api
 * @since 100.0.2
 */
class Grid extends \Magento\Backend\Block\Widget\Grid\Extended
{
    /**
     * Core registry
     *
     * @var \Magento\Framework\Registry
     */
    protected $_coreRegistry = null;

    /**
     * @var \Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory
     */
    protected $_salesRuleCoupon;

    /**
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \Magento\Backend\Helper\Data $backendHelper
     * @param \Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory $salesRuleCoupon
     * @param \Magento\Framework\Registry $coreRegistry
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Backend\Helper\Data $backendHelper,
        \Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory $salesRuleCoupon,
        \Magento\Framework\Registry $coreRegistry,
        array $data = []
    ) {
        $this->_coreRegistry = $coreRegistry;
        $this->_salesRuleCoupon = $salesRuleCoupon;
        parent::__construct($context, $backendHelper, $data);
    }

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        parent::_construct();
        $this->setId('couponCodesGrid');
        $this->setUseAjax(true);
    }

    /**
     * @inheritdoc
     */
    protected function _prepareCollection()
    {
        $priceRule = $this->_coreRegistry->registry(\Magento\SalesRule\Model\RegistryConstants::CURRENT_SALES_RULE);

        /**
         * @var \Magento\SalesRule\Model\ResourceModel\Coupon\Collection $collection
         */
        $collection = $this->_salesRuleCoupon->create()->addRuleToFilter($priceRule)->addGeneratedCouponsFilter();

        if ($this->_isExport && $this->getMassactionBlock()->isAvailable()) {
            $itemIds = $this->getMassactionBlock()->getSelected();
            if (!empty($itemIds)) {
                $collection->addFieldToFilter('coupon_id', ['in' => $itemIds]);
            }
        }

        $this->setCollection($collection);

        return parent::_prepareCollection();
    }

    /**
     * @inheritdoc
     */
    protected function _prepareColumns()
    {
        $this->addColumn('code', ['header' => __('Coupon Code'), 'index' => 'code']);

        $this->addColumn(
            'created_at',
            [
                'header' => __('Created'),
                'index' => 'created_at',
                'type' => 'datetime',
                'align' => 'center',
                'width' => '160'
            ]
        );

        $this->addColumn(
            'used',
            [
                'header' => __('Used'),
                'index' => 'times_used',
                'width' => '100',
                'type' => 'options',
                'options' => [__('No'), __('Yes')],
                'renderer' =>
                    \Magento\SalesRule\Block\Adminhtml\Promo\Quote\Edit\Tab\Coupons\Grid\Column\Renderer\Used::class,
                'filter_condition_callback' => [$this->_salesRuleCoupon->create(), 'addIsUsedFilterCallback']
            ]
        );

        $this->addColumn(
            'times_used',
            ['header' => __('Times Used'), 'index' => 'times_used', 'width' => '50', 'type' => 'number']
        );

        $this->addExportType('*/*/exportCouponsCsv', __('CSV'));
        $this->addExportType('*/*/exportCouponsXml', __('Excel XML'));
        return parent::_prepareColumns();
    }

    /**
     * @inheritdoc
     */
    protected function _prepareMassaction()
    {
        $this->setMassactionIdField('coupon_id');
        $this->getMassactionBlock()->setFormFieldName('ids');
        $this->getMassactionBlock()->setUseAjax(true);
        $this->getMassactionBlock()->setHideFormElement(true);

        $this->getMassactionBlock()->addItem(
            'delete',
            [
                'label' => __('Delete'),
                'url' => $this->getUrl('sales_rule/*/couponsMassDelete', ['_current' => true]),
                'confirm' => __('Are you sure you want to delete the selected coupon(s)?'),
                'complete' => 'refreshCouponCodesGrid'
            ]
        );

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getGridUrl()
    {
        return $this->getUrl('sales_rule/*/couponsGrid', ['_current' => true]);
    }
}
