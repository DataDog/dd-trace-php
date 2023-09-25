<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Model\Layer\Filter;

/**
 * Test class for \Magento\Catalog\Model\Layer\Filter\Category.
 *
 * @magentoDataFixture Magento/Catalog/_files/categories.php
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CategoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Model\Layer\Filter\Category
     */
    protected $_model;

    /**
     * @var \Magento\Catalog\Model\Category
     */
    protected $_category;

    protected function setUp(): void
    {
        $this->_category = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Catalog\Model\Category::class
        );
        $this->_category->load(5);
        $layer = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->create(
                \Magento\Catalog\Model\Layer\Category::class,
                ['data' => ['current_category' => $this->_category]]
            );
        $this->_model = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->create(\Magento\Catalog\Model\Layer\Filter\Category::class, ['layer' => $layer]);
    }

    public function testGetResetValue()
    {
        $this->assertNull($this->_model->getResetValue());
    }

    public function testApplyNothing()
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->_model->apply(
            $objectManager->get(\Magento\TestFramework\Request::class),
            \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
                \Magento\Framework\View\LayoutInterface::class
            )->createBlock(
                \Magento\Framework\View\Element\Text::class
            )
        );
        /** @var $objectManager \Magento\TestFramework\ObjectManager */
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->assertNull($objectManager->get(\Magento\Framework\Registry::class)->registry('current_category_filter'));
    }

    public function testApply()
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $request = $objectManager->get(\Magento\TestFramework\Request::class);
        $request->setParam('cat', 3);
        $this->_model->apply(
            $request,
            \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
                \Magento\Framework\View\LayoutInterface::class
            )->createBlock(
                \Magento\Framework\View\Element\Text::class
            )
        );

        /** @var $category \Magento\Catalog\Model\Category */
        $category = $objectManager->get(\Magento\Framework\Registry::class)->registry('current_category_filter');
        $this->assertInstanceOf(\Magento\Catalog\Model\Category::class, $category);
        $this->assertEquals(3, $category->getId());

        return $this->_model;
    }

    /**
     * @depends testApply
     */
    public function testGetResetValueApplied(\Magento\Catalog\Model\Layer\Filter\Category $modelApplied)
    {
        $this->assertEquals(2, $modelApplied->getResetValue());
    }

    public function testGetName()
    {
        $this->assertEquals('Category', $this->_model->getName());
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/categories.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation disabled
     */
    public function testGetItems()
    {
        \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->get(\Magento\Framework\Registry::class)->unregister('current_category_filter');
        $category = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Catalog\Model\Category::class
        );
        $category->load(5);
        $layer = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->create(
                \Magento\Catalog\Model\Layer\Category::class,
                ['data' => ['current_category' => $category]]
            );
        $model = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->create(\Magento\Catalog\Model\Layer\Filter\Category::class, ['layer' => $layer]);

        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $request = $objectManager->get(\Magento\TestFramework\Request::class);
        $request->setParam('cat', 3);
        $model->apply(
            $request,
            \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
                \Magento\Framework\View\LayoutInterface::class
            )->createBlock(
                \Magento\Framework\View\Element\Text::class
            )
        );

        /** @var $category \Magento\Catalog\Model\Category */
        $category = $objectManager->get(\Magento\Framework\Registry::class)->registry('current_category_filter');
        $this->assertInstanceOf(\Magento\Catalog\Model\Category::class, $category);
        $this->assertEquals(3, $category->getId());

        $items = $model->getItems();

        $this->assertIsArray($items);
        $this->assertCount(2, $items);

        /** @var $item \Magento\Catalog\Model\Layer\Filter\Item */
        $item = $items[0];

        $this->assertInstanceOf(\Magento\Catalog\Model\Layer\Filter\Item::class, $item);
        $this->assertSame($model, $item->getFilter());
        $this->assertEquals('Category 1.1', $item->getLabel());
        $this->assertEquals(4, $item->getValue());
        $this->assertEquals(2, $item->getCount());

        $item = $items[1];
        $this->assertInstanceOf(\Magento\Catalog\Model\Layer\Filter\Item::class, $item);
        $this->assertEquals('Category 1.2', $item->getLabel());
        $this->assertEquals(13, $item->getValue());
        $this->assertEquals(2, $item->getCount());
    }
}
