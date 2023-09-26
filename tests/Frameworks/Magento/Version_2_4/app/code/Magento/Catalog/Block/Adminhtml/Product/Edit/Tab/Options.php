<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Block\Adminhtml\Product\Edit\Tab;

use Magento\Backend\Block\Widget;

/**
 * @api
 * @since 100.0.2
 */
class Options extends Widget
{
    /**
     * @var string
     */
    protected $_template = 'Magento_Catalog::catalog/product/edit/options.phtml';

    /**
     * @return Widget
     */
    protected function _prepareLayout()
    {
        $this->addChild(
            'add_button',
            \Magento\Backend\Block\Widget\Button::class,
            ['label' => __('Add New Option'), 'class' => 'add', 'id' => 'add_new_defined_option']
        );

        $this->addChild('options_box', \Magento\Catalog\Block\Adminhtml\Product\Edit\Tab\Options\Option::class);

        $this->addChild(
            'import_button',
            \Magento\Backend\Block\Widget\Button::class,
            ['label' => __('Import Options'), 'class' => 'add', 'id' => 'import_new_defined_option']
        );

        return parent::_prepareLayout();
    }

    /**
     * @return string
     */
    public function getAddButtonHtml()
    {
        return $this->getChildHtml('add_button');
    }

    /**
     * @return string
     */
    public function getOptionsBoxHtml()
    {
        return $this->getChildHtml('options_box');
    }
}
