<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Model\Layer\Filter;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use PHPUnit\Framework\MockObject\MockObject as MockObject;

/**
 * Test for \Magento\Catalog\Model\Layer\Filter\Category
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CategoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Model\Layer\Filter\Item\DataBuilder
     */
    private $itemDataBuilder;

    /**
     * @var \Magento\Catalog\Model\Category|MockObject
     */
    private $category;

    /**
     * @var \Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection|MockObject
     */
    private $collection;

    /**
     * @var \Magento\Catalog\Model\Layer|MockObject
     */
    private $layer;

    /**
     * @var \Magento\Catalog\Model\Layer\Filter\DataProvider\Category|MockObject
     */
    private $dataProvider;

    /**
     * @var \Magento\Catalog\Model\Layer\Filter\Category
     */
    private $target;

    /** @var \Magento\Framework\App\RequestInterface|MockObject */
    private $request;

    /** @var  \Magento\Catalog\Model\Layer\Filter\ItemFactory|MockObject */
    private $filterItemFactory;

    protected function setUp(): void
    {
        $this->request = $this->getMockBuilder(\Magento\Framework\App\RequestInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getParam'])
            ->getMockForAbstractClass();

        $dataProviderFactory = $this->getMockBuilder(
            \Magento\Catalog\Model\Layer\Filter\DataProvider\CategoryFactory::class
        )->disableOriginalConstructor()->setMethods(['create'])->getMock();

        $this->dataProvider = $this->getMockBuilder(\Magento\Catalog\Model\Layer\Filter\DataProvider\Category::class)
            ->disableOriginalConstructor()
            ->setMethods(['setCategoryId', 'getCategory'])
            ->getMock();

        $dataProviderFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->dataProvider);

        $this->category = $this->getMockBuilder(\Magento\Catalog\Model\Category::class)
            ->disableOriginalConstructor()
            ->setMethods(['getId', 'getChildrenCategories', 'getIsActive'])
            ->getMock();

        $this->dataProvider->expects($this->any())
            ->method('getCategory', 'isValid')
            ->willReturn($this->category);

        $this->layer = $this->getMockBuilder(\Magento\Catalog\Model\Layer::class)
            ->disableOriginalConstructor()
            ->setMethods(['getState', 'getProductCollection'])
            ->getMock();

        $this->state = $this->getMockBuilder(\Magento\Catalog\Model\Layer\State::class)
            ->disableOriginalConstructor()
            ->setMethods(['addFilter'])
            ->getMock();
        $this->layer->expects($this->any())
            ->method('getState')
            ->willReturn($this->state);

        $this->collection = $this->getMockBuilder(
            \Magento\Catalog\Model\ResourceModel\Product\Collection::class
        )
            ->disableOriginalConstructor()
            ->setMethods(['addCategoryFilter', 'getFacetedData', 'addCountToCategories'])
            ->getMock();

        $this->layer->expects($this->any())
            ->method('getProductCollection')
            ->willReturn($this->collection);

        $this->itemDataBuilder = $this->getMockBuilder(\Magento\Catalog\Model\Layer\Filter\Item\DataBuilder::class)
            ->disableOriginalConstructor()
            ->setMethods(['addItemData', 'build'])
            ->getMock();

        $this->filterItemFactory = $this->getMockBuilder(
            \Magento\Catalog\Model\Layer\Filter\ItemFactory::class
        )->disableOriginalConstructor()->setMethods(['create'])->getMock();

        $filterItem = $this->getMockBuilder(
            \Magento\Catalog\Model\Layer\Filter\Item::class
        )->disableOriginalConstructor()
            ->setMethods(['setFilter', 'setLabel', 'setValue', 'setCount'])
            ->getMock();
        $filterItem->expects($this->any())
            ->method($this->anything())
            ->willReturnSelf();
        $this->filterItemFactory->expects($this->any())
            ->method('create')
            ->willReturn($filterItem);

        $escaper = $this->getMockBuilder(\Magento\Framework\Escaper::class)
            ->disableOriginalConstructor()
            ->setMethods(['escapeHtml'])
            ->getMock();
        $escaper->expects($this->any())
            ->method('escapeHtml')
            ->willReturnArgument(0);

        $objectManagerHelper = new ObjectManagerHelper($this);
        $this->target = $objectManagerHelper->getObject(
            \Magento\Catalog\Model\Layer\Filter\Category::class,
            [
                'categoryDataProviderFactory' => $dataProviderFactory,
                'layer' => $this->layer,
                'itemDataBuilder' => $this->itemDataBuilder,
                'filterItemFactory' => $this->filterItemFactory,
                'escaper' => $escaper,
            ]
        );
    }

    /** @var  \Magento\Catalog\Model\Layer\State|MockObject */
    private $state;

    /**
     * @param $requestValue
     * @param $idValue
     * @param $isIdUsed
     * @dataProvider applyWithEmptyRequestDataProvider
     */
    public function testApplyWithEmptyRequest($requestValue, $idValue)
    {
        $requestField = 'test_request_var';
        $idField = 'id';

        $this->target->setRequestVar($requestField);

        $this->request->expects($this->at(0))
            ->method('getParam')
            ->with($requestField)
            ->willReturnCallback(
                
                    function ($field) use ($requestField, $idField, $requestValue, $idValue) {
                        switch ($field) {
                            case $requestField:
                                return $requestValue;
                            case $idField:
                                return $idValue;
                        }
                    }
                
            );

        $result = $this->target->apply($this->request);
        $this->assertSame($this->target, $result);
    }

    /**
     * @return array
     */
    public function applyWithEmptyRequestDataProvider()
    {
        return [
            [
                'requestValue' => null,
                'id' => 0,
            ],
            [
                'requestValue' => 0,
                'id' => false,
            ],
            [
                'requestValue' => 0,
                'id' => null,
            ]
        ];
    }

    public function testApply()
    {
        $categoryId = 123;
        $requestVar = 'test_request_var';

        $this->target->setRequestVar($requestVar);
        $this->request->expects($this->any())
            ->method('getParam')
            ->willReturnCallback(
                
                    function ($field) use ($requestVar, $categoryId) {
                        $this->assertTrue(in_array($field, [$requestVar, 'id']));

                        return $categoryId;
                    }
                
            );

        $this->dataProvider->expects($this->once())
            ->method('setCategoryId')
            ->with($categoryId)
            ->willReturnSelf();

        $this->category->expects($this->once())
            ->method('getId')
            ->willReturn($categoryId);

        $this->collection->expects($this->once())
            ->method('addCategoryFilter')
            ->with($this->category)
            ->willReturnSelf();

        $this->target->apply($this->request);
    }

    public function testGetItems()
    {
        $this->category->expects($this->any())
            ->method('getIsActive')
            ->willReturn(true);

        $category1 = $this->getMockBuilder(\Magento\Catalog\Model\Category::class)
            ->disableOriginalConstructor()
            ->setMethods(['getId', 'getName', 'getIsActive', 'getProductCount'])
            ->getMock();
        $category1->expects($this->atLeastOnce())
            ->method('getId')
            ->willReturn(120);
        $category1->expects($this->once())
            ->method('getName')
            ->willReturn('Category 1');
        $category1->expects($this->once())
            ->method('getIsActive')
            ->willReturn(true);
        $category1->expects($this->any())
            ->method('getProductCount')
            ->willReturn(10);

        $category2 = $this->getMockBuilder(\Magento\Catalog\Model\Category::class)
            ->disableOriginalConstructor()
            ->setMethods(['getId', 'getName', 'getIsActive', 'getProductCount'])
            ->getMock();
        $category2->expects($this->atLeastOnce())
            ->method('getId')
            ->willReturn(5641);
        $category2->expects($this->once())
            ->method('getName')
            ->willReturn('Category 2');
        $category2->expects($this->once())
            ->method('getIsActive')
            ->willReturn(true);
        $category2->expects($this->any())
            ->method('getProductCount')
            ->willReturn(45);
        $categories = [
            $category1,
            $category2,
        ];
        $this->category->expects($this->once())
            ->method('getChildrenCategories')
            ->willReturn($categories);

        $builtData = [
            [
                'label' => 'Category 1',
                'value' => 120,
                'count' => 10,
            ],
            [
                'label' => 'Category 2',
                'value' => 5641,
                'count' => 45,
            ],
        ];

        $this->itemDataBuilder->expects($this->at(0))
            ->method('addItemData')
            ->with(
                'Category 1',
                120,
                10
            )
            ->willReturnSelf();
        $this->itemDataBuilder->expects($this->at(1))
            ->method('addItemData')
            ->with(
                'Category 2',
                5641,
                45
            )
            ->willReturnSelf();
        $this->itemDataBuilder->expects($this->once())
            ->method('build')
            ->willReturn($builtData);

        $this->target->getItems();
    }
}
