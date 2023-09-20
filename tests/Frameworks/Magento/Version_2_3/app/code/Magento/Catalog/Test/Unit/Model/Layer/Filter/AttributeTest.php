<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Test\Unit\Model\Layer\Filter;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use PHPUnit\Framework\MockObject\MockObject as MockObject;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AttributeTest extends \PHPUnit\Framework\TestCase
{
    /** @var  \Magento\Catalog\Model\ResourceModel\Layer\Filter\Attribute|MockObject */
    private $filterAttribute;

    /**
     * @var \Magento\Catalog\Model\Layer\Filter\Attribute
     */
    private $target;

    /** @var  \Magento\Eav\Model\Entity\Attribute\Frontend\AbstractFrontend|MockObject */
    private $frontend;

    /** @var  \Magento\Catalog\Model\Layer\State|MockObject */
    private $state;

    /** @var  \Magento\Eav\Model\Entity\Attribute|MockObject */
    private $attribute;

    /** @var \Magento\Framework\App\RequestInterface|MockObject */
    private $request;

    /** @var  \Magento\Catalog\Model\ResourceModel\Layer\Filter\AttributeFactory|MockObject */
    private $filterAttributeFactory;

    /** @var  \Magento\Catalog\Model\Layer\Filter\ItemFactory|MockObject */
    private $filterItemFactory;

    /** @var  \Magento\Store\Model\StoreManagerInterface|MockObject */
    private $storeManager;

    /** @var  \Magento\Catalog\Model\Layer|MockObject */
    private $layer;

    /** @var  \Magento\Catalog\Model\Layer\Filter\Item\DataBuilder|MockObject */
    private $itemDataBuilder;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function setUp(): void
    {
        /** @var \Magento\Catalog\Model\Layer\Filter\ItemFactory $filterItemFactory */
        $this->filterItemFactory = $this->getMockBuilder(\Magento\Catalog\Model\Layer\Filter\ItemFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        /** @var \Magento\Store\Model\StoreManagerInterface $storeManager */
        $this->storeManager = $this->getMockBuilder(\Magento\Store\Model\StoreManagerInterface::class)
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMockForAbstractClass();
        /** @var \Magento\Catalog\Model\Layer $layer */
        $this->layer = $this->getMockBuilder(\Magento\Catalog\Model\Layer::class)
            ->disableOriginalConstructor()
            ->setMethods(['getState'])
            ->getMock();
        /** @var \Magento\Catalog\Model\Layer\Filter\Item\DataBuilder $itemDataBuilder */
        $this->itemDataBuilder = $this->getMockBuilder(\Magento\Catalog\Model\Layer\Filter\Item\DataBuilder::class)
            ->disableOriginalConstructor()
            ->setMethods(['addItemData', 'build'])
            ->getMock();

        $this->filterAttribute = $this->getMockBuilder(
            \Magento\Catalog\Model\ResourceModel\Layer\Filter\Attribute::class
        )->disableOriginalConstructor()
            ->setMethods(['getCount', 'applyFilterToCollection'])
            ->getMock();

        $this->filterAttribute->expects($this->any())
            ->method('applyFilterToCollection')
            ->willReturnSelf();

        $this->filterAttributeFactory = $this->getMockBuilder(
            \Magento\Catalog\Model\ResourceModel\Layer\Filter\AttributeFactory::class
        )
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $this->filterAttributeFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->filterAttribute);

        $this->state = $this->getMockBuilder(\Magento\Catalog\Model\Layer\State::class)
            ->disableOriginalConstructor()
            ->setMethods(['addFilter'])
            ->getMock();
        $this->layer->expects($this->any())
            ->method('getState')
            ->willReturn($this->state);

        $this->frontend = $this->getMockBuilder(\Magento\Eav\Model\Entity\Attribute\Frontend\AbstractFrontend::class)
            ->disableOriginalConstructor()
            ->setMethods(['getOption', 'getSelectOptions'])
            ->getMock();
        $this->attribute = $this->getMockBuilder(\Magento\Eav\Model\Entity\Attribute::class)
            ->disableOriginalConstructor()
            ->setMethods(['getAttributeCode', 'getFrontend', 'getIsFilterable'])
            ->getMock();
        $this->attribute->expects($this->atLeastOnce())
            ->method('getFrontend')
            ->willReturn($this->frontend);

        $this->request = $this->getMockBuilder(\Magento\Framework\App\RequestInterface::class)
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMockForAbstractClass();

        $stripTagsFilter = $this->getMockBuilder(\Magento\Framework\Filter\StripTags::class)
            ->disableOriginalConstructor()
            ->setMethods(['filter'])
            ->getMock();
        $stripTagsFilter->expects($this->any())
            ->method('filter')
            ->willReturnArgument(0);

        $string = $this->getMockBuilder(\Magento\Framework\Stdlib\StringUtils::class)
            ->disableOriginalConstructor()
            ->setMethods(['strlen'])
            ->getMock();
        $string->expects($this->any())
            ->method('strlen')
            ->willReturnCallback(
                
                    function ($value) {
                        return strlen($value);
                    }
                
            );

        $objectManagerHelper = new ObjectManagerHelper($this);
        $this->target = $objectManagerHelper->getObject(
            \Magento\Catalog\Model\Layer\Filter\Attribute::class,
            [
                'filterItemFactory' => $this->filterItemFactory,
                'storeManager' => $this->storeManager,
                'layer' => $this->layer,
                'itemDataBuilder' => $this->itemDataBuilder,
                'filterAttributeFactory' => $this->filterAttributeFactory,
                'tagFilter' => $stripTagsFilter,
                'string' => $string,
            ]
        );
    }

    public function testApplyFilter()
    {
        $attributeCode = 'attributeCode';
        $attributeValue = 'attributeValue';
        $attributeLabel = 'attributeLabel';

        $this->attribute->expects($this->any())
            ->method('getAttributeCode')
            ->willReturn($attributeCode);

        $this->target->setAttributeModel($this->attribute);

        $this->request->expects($this->once())
            ->method('getParam')
            ->with($attributeCode)
            ->willReturn($attributeValue);

        $this->frontend->expects($this->once())
            ->method('getOption')
            ->with($attributeValue)
            ->willReturn($attributeLabel);

        $filterItem = $this->createFilterItem(0, $attributeLabel, $attributeValue, 0);

        $filterItem->expects($this->once())
            ->method('setFilter')
            ->with($this->target)
            ->willReturnSelf();

        $filterItem->expects($this->once())
            ->method('setLabel')
            ->with($attributeLabel)
            ->willReturnSelf();

        $filterItem->expects($this->once())
            ->method('setValue')
            ->with($attributeValue)
            ->willReturnSelf();

        $filterItem->expects($this->once())
            ->method('setCount')
            ->with(0)
            ->willReturnSelf();

        $this->state->expects($this->once())
            ->method('addFilter')
            ->with($filterItem)
            ->willReturnSelf();

        $result = $this->target->apply($this->request);

        $this->assertEquals($this->target, $result);
    }

    public function testGetItems()
    {
        $attributeCode = 'attributeCode';
        $attributeValue = 'attributeValue';
        $attributeLabel = 'attributeLabel';

        $this->attribute->expects($this->once())
            ->method('getAttributeCode')
            ->willReturn($attributeCode);

        $this->target->setAttributeModel($this->attribute);

        $this->request->expects($this->once())
            ->method('getParam')
            ->with($attributeCode)
            ->willReturn($attributeValue);

        $this->frontend->expects($this->once())
            ->method('getOption')
            ->with($attributeValue)
            ->willReturn($attributeLabel);

        $filterItem = $this->createFilterItem(0, $attributeLabel, $attributeValue, 0);

        $this->state->expects($this->once())
            ->method('addFilter')
            ->with($filterItem)
            ->willReturnSelf();

        $expectedFilterItems = [];

        $result = $this->target->apply($this->request)->getItems();

        $this->assertEquals($expectedFilterItems, $result);
    }

    /**
     * @param int $index
     * @param string $label
     * @param string $value
     * @param int $count
     * @return \Magento\Catalog\Model\Layer\Filter\Item|MockObject
     */
    private function createFilterItem($index, $label, $value, $count)
    {
        $filterItem = $this->getMockBuilder(\Magento\Catalog\Model\Layer\Filter\Item::class)
            ->disableOriginalConstructor()
            ->setMethods(['setFilter', 'setLabel', 'setValue', 'setCount'])
            ->getMock();

        $filterItem->expects($this->once())
            ->method('setFilter')
            ->with($this->target)
            ->willReturnSelf();

        $filterItem->expects($this->once())
            ->method('setLabel')
            ->with($label)
            ->willReturnSelf();

        $filterItem->expects($this->once())
            ->method('setValue')
            ->with($value)
            ->willReturnSelf();

        $filterItem->expects($this->once())
            ->method('setCount')
            ->with($count)
            ->willReturnSelf();

        $this->filterItemFactory->expects($this->at($index))
            ->method('create')
            ->willReturn($filterItem);

        return $filterItem;
    }
}
