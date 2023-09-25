<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Reports\Block\Adminhtml\Product\Lowstock;

/**
 * Adminhtml low stock products report grid block
 *
 * @api
 * @author      Magento Core Team <core@magentocommerce.com>
 * @since 100.0.2
 */
class Grid extends \Magento\Backend\Block\Widget\Grid
{
    /**
     * @var \Magento\Reports\Model\ResourceModel\Product\Lowstock\CollectionFactory
     */
    protected $_lowstocksFactory;

    /**
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \Magento\Backend\Helper\Data $backendHelper
     * @param \Magento\Reports\Model\ResourceModel\Product\Lowstock\CollectionFactory $lowstocksFactory
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Backend\Helper\Data $backendHelper,
        \Magento\Reports\Model\ResourceModel\Product\Lowstock\CollectionFactory $lowstocksFactory,
        array $data = []
    ) {
        $this->_lowstocksFactory = $lowstocksFactory;
        parent::__construct($context, $backendHelper, $data);
    }

    /**
     * @return \Magento\Backend\Block\Widget\Grid
     */
    protected function _prepareCollection()
    {
        $website = $this->getRequest()->getParam('website');
        $group = $this->getRequest()->getParam('group');
        $store = $this->getRequest()->getParam('store');

        if ($website) {
            $storeIds = $this->_storeManager->getWebsite($website)->getStoreIds();
            $storeId = array_pop($storeIds);
        } elseif ($group) {
            $storeIds = $this->_storeManager->getGroup($group)->getStoreIds();
            $storeId = array_pop($storeIds);
        } elseif ($store) {
            $storeId = (int)$store;
        } else {
            $storeId = null;
        }

        /** @var $collection \Magento\Reports\Model\ResourceModel\Product\Lowstock\Collection  */
        $collection = $this->_lowstocksFactory->create()->addAttributeToSelect(
            '*'
        )->filterByIsQtyProductTypes()->joinInventoryItem(
            'qty'
        )->useManageStockFilter(
            $storeId
        )->useNotifyStockQtyFilter(
            $storeId
        )->setOrder(
            'qty',
            \Magento\Framework\Data\Collection::SORT_ORDER_ASC
        );

        if ($storeId) {
            $collection->addStoreFilter($storeId);
        }

        $this->setCollection($collection);
        return parent::_prepareCollection();
    }
}
