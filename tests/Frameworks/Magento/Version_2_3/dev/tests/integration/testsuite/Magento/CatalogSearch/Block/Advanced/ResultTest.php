<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogSearch\Block\Advanced;

class ResultTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\LayoutInterface
     */
    protected $_layout;

    /**
     * @var \Magento\CatalogSearch\Block\Advanced\Result
     */
    protected $_block;

    protected function setUp(): void
    {
        $this->_layout = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Framework\View\LayoutInterface::class
        );
        $this->_block = $this->_layout->createBlock(\Magento\CatalogSearch\Block\Advanced\Result::class, 'block');
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testSetListOrders()
    {
        $sortOptions = [
            'option1' => 'Label Option 1',
            'position' => 'Label Position',
            'option3' => 'Label Option 2',
        ];
        /** @var \Magento\Catalog\Model\Category $category */
        $category = $this->createPartialMock(\Magento\Catalog\Model\Category::class, ['getAvailableSortByOptions']);
        $category->expects($this->atLeastOnce())
            ->method('getAvailableSortByOptions')
            ->willReturn($sortOptions);
        $category->setId(100500); // Any id - just for layer navigation
        /** @var \Magento\Catalog\Model\Layer\Resolver $resolver */
        $resolver = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->get(\Magento\Catalog\Model\Layer\Resolver::class);
        $resolver->get()->setCurrentCategory($category);

        $childBlock = $this->_layout->addBlock(
            \Magento\Framework\View\Element\Text::class,
            'search_result_list',
            'block'
        );

        $expectedOptions = ['option1' => 'Label Option 1', 'option3' => 'Label Option 2'];
        $this->assertNotEquals($expectedOptions, $childBlock->getAvailableOrders());
        $this->_block->setListOrders();
        $this->assertEquals($expectedOptions, $childBlock->getAvailableOrders());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testSetListModes()
    {
        /** @var $childBlock \Magento\Framework\View\Element\Text */
        $childBlock = $this->_layout->addBlock(
            \Magento\Framework\View\Element\Text::class,
            'search_result_list',
            'block'
        );
        $this->assertEmpty($childBlock->getModes());
        $this->_block->setListModes();
        $this->assertNotEmpty($childBlock->getModes());
    }

    public function testSetListCollection()
    {
        /** @var $childBlock \Magento\Framework\View\Element\Text */
        $childBlock = $this->_layout->addBlock(
            \Magento\Framework\View\Element\Text::class,
            'search_result_list',
            'block'
        );
        $this->assertEmpty($childBlock->getCollection());
        $this->_block->setListCollection();
        $this->assertInstanceOf(
            \Magento\CatalogSearch\Model\ResourceModel\Advanced\Collection::class,
            $childBlock->getCollection()
        );
    }
}
