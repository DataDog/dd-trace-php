<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\CatalogInventory\Block\Adminhtml\Form\Field;

class CustomergroupTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\CatalogInventory\Block\Adminhtml\Form\Field\Customergroup
     */
    protected $_block;

    protected function setUp(): void
    {
        $this->_block = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\CatalogInventory\Block\Adminhtml\Form\Field\Customergroup::class
        );
    }

    public function testToHtml()
    {
        $this->_block->setClass('customer_group_select admin__control-select');
        $this->_block->setId('123');
        $this->_block->setTitle('Customer Group');
        $this->_block->setInputName('groups[item_options]');
        $expectedResult = '<select name="groups[item_options]" id="123" '
            . 'class="customer_group_select admin__control-select" '
            . 'title="Customer Group" ><option value="32000" >ALL GROUPS</option><option value="0" >NOT LOGGED IN'
            . '</option><option value="1" >General</option><option value="2" >Wholesale</option><option value="3" >'
            . 'Retailer</option></select>';
        $this->assertEquals($expectedResult, $this->_block->_toHtml());
    }
}
