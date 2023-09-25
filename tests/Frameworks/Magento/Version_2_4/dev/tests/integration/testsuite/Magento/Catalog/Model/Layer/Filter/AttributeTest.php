<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Model\Layer\Filter;

/**
 * Test class for \Magento\Catalog\Model\Layer\Filter\Attribute.
 *
 * @magentoDbIsolation disabled
 *
 * @magentoDataFixture Magento/Catalog/Model/Layer/Filter/_files/attribute_with_option.php
 */
class AttributeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Model\Layer\Filter\Attribute
     */
    protected $_model;

    /**
     * @var int
     */
    protected $_attributeOptionId;

    /**
     * @var \Magento\Catalog\Model\Layer
     */
    protected $_layer;

    protected function setUp(): void
    {
        /** @var $attribute \Magento\Catalog\Model\Entity\Attribute */
        $attribute = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Catalog\Model\Entity\Attribute::class
        );
        $attribute->loadByCode('catalog_product', 'attribute_with_option');
        foreach ($attribute->getSource()->getAllOptions() as $optionInfo) {
            if ($optionInfo['label'] == 'Option Label') {
                $this->_attributeOptionId = $optionInfo['value'];
                break;
            }
        }

        $this->_layer = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->create(\Magento\Catalog\Model\Layer\Category::class);
        $this->_model = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->create(\Magento\Catalog\Model\Layer\Filter\Attribute::class, ['layer' => $this->_layer]);
        $this->_model->setData([
            'attribute_model' => $attribute,
        ]);
    }

    public function testOptionIdNotEmpty()
    {
        $this->assertNotEmpty($this->_attributeOptionId, 'Fixture attribute option id.'); // just in case
    }

    public function testApplyInvalid()
    {
        $this->assertEmpty($this->_model->getLayer()->getState()->getFilters());
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $request = $objectManager->get(\Magento\TestFramework\Request::class);
        $request->setParam('attribute', []);
        $this->_model->apply(
            $request,
            \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
                \Magento\Framework\View\LayoutInterface::class
            )->createBlock(
                \Magento\Framework\View\Element\Text::class
            )
        );

        $this->assertEmpty($this->_model->getLayer()->getState()->getFilters());
    }

    public function testApply()
    {
        $this->assertEmpty($this->_model->getLayer()->getState()->getFilters());

        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $request = $objectManager->get(\Magento\TestFramework\Request::class);
        $request->setParam('attribute', $this->_attributeOptionId);
        $this->_model->apply($request);

        $this->assertNotEmpty($this->_model->getLayer()->getState()->getFilters());
    }

    public function testGetItems()
    {
        $items = $this->_model->getItems();

        $this->assertIsArray($items);
        $this->assertCount(1, $items);

        /** @var $item \Magento\Catalog\Model\Layer\Filter\Item */
        $item = $items[0];

        $this->assertInstanceOf(\Magento\Catalog\Model\Layer\Filter\Item::class, $item);
        $this->assertSame($this->_model, $item->getFilter());
        $this->assertEquals('Option Label', $item->getLabel());
        $this->assertEquals($this->_attributeOptionId, $item->getValue());
        $this->assertEquals(1, $item->getCount());
    }
}
