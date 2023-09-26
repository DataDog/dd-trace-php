<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Product attributes grid
 *
 * @author     Magento Core Team <core@magentocommerce.com>
 */
namespace Magento\Catalog\Block\Adminhtml\Product\Attribute;

use Magento\Eav\Block\Adminhtml\Attribute\Grid\AbstractGrid;

/**
 * @SuppressWarnings(PHPMD.DepthOfInheritance)
 */
class Grid extends AbstractGrid
{
    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory
     */
    protected $_collectionFactory;

    /**
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \Magento\Backend\Helper\Data $backendHelper
     * @param \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory $collectionFactory
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Backend\Helper\Data $backendHelper,
        \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory $collectionFactory,
        array $data = []
    ) {
        $this->_collectionFactory = $collectionFactory;
        $this->_module = 'catalog';
        parent::__construct($context, $backendHelper, $data);
    }

    /**
     * Prepare product attributes grid collection object
     *
     * @return $this
     */
    protected function _prepareCollection()
    {
        $collection = $this->_collectionFactory->create()->addVisibleFilter();
        $this->setCollection($collection);

        return parent::_prepareCollection();
    }

    /**
     * Prepare product attributes grid columns
     *
     * @return $this
     */
    protected function _prepareColumns()
    {
        parent::_prepareColumns();

        $this->addColumnAfter(
            'is_visible',
            [
                'header' => __('Visible'),
                'sortable' => true,
                'index' => 'is_visible_on_front',
                'type' => 'options',
                'options' => ['1' => __('Yes'), '0' => __('No')],
                'align' => 'center'
            ],
            'frontend_label'
        );

        $this->addColumnAfter(
            'is_global',
            [
                'header' => __('Scope'),
                'sortable' => true,
                'index' => 'is_global',
                'type' => 'options',
                'options' => [
                    \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE => __('Store View'),
                    \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_WEBSITE => __('Web Site'),
                    \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL => __('Global'),
                ],
                'align' => 'center'
            ],
            'is_visible'
        );

        $this->addColumn(
            'is_searchable',
            [
                'header' => __('Searchable'),
                'sortable' => true,
                'index' => 'is_searchable',
                'type' => 'options',
                'options' => ['1' => __('Yes'), '0' => __('No')],
                'align' => 'center'
            ]
        );

        $this->_eventManager->dispatch('product_attribute_grid_build', ['grid' => $this]);

        $this->addColumnAfter(
            'is_comparable',
            [
                'header' => __('Comparable'),
                'sortable' => true,
                'index' => 'is_comparable',
                'type' => 'options',
                'options' => ['1' => __('Yes'), '0' => __('No')],
                'align' => 'center'
            ],
            'is_filterable'
        );

        return $this;
    }
}
