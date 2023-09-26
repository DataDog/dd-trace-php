<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Adminhtml catalog product sets main page toolbar
 *
 * @author      Magento Core Team <core@magentocommerce.com>
 */
namespace Magento\Catalog\Block\Adminhtml\Product\Attribute\Set\Toolbar;

/**
 * @api
 * @since 100.0.2
 */
class Main extends \Magento\Backend\Block\Template
{
    /**
     * @var string
     */
    protected $_template = 'Magento_Catalog::catalog/product/attribute/set/toolbar/main.phtml';

    /**
     * @return $this
     */
    protected function _prepareLayout()
    {
        $this->getToolbar()->addChild(
            'addButton',
            \Magento\Backend\Block\Widget\Button::class,
            [
                'label' => __('Add Attribute Set'),
                'onclick' => 'setLocation(\'' . $this->getUrl('catalog/*/add') . '\')',
                'class' => 'add primary add-set'
            ]
        );
        return parent::_prepareLayout();
    }

    /**
     * @return string
     */
    public function getNewButtonHtml()
    {
        return $this->getChildHtml('addButton');
    }

    /**
     * @return \Magento\Framework\Phrase
     */
    protected function _getHeader()
    {
        return __('Attribute Sets');
    }

    /**
     * @return string
     */
    protected function _toHtml()
    {
        $this->_eventManager->dispatch(
            'adminhtml_catalog_product_attribute_set_toolbar_main_html_before',
            ['block' => $this]
        );
        return parent::_toHtml();
    }
}
