<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Test\Unit\Model\Product\Attribute;

use Magento\Catalog\Model\Product\Attribute\TypesList;

class TypesListTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var TypesList
     */
    protected $model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $inputTypeFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $attributeTypeFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\Api\DataObjectHelper
     */
    protected $dataObjectHelperMock;

    protected function setUp(): void
    {
        $this->inputTypeFactoryMock = $this->createPartialMock(
            \Magento\Catalog\Model\Product\Attribute\Source\InputtypeFactory::class,
            ['create', '__wakeup']
        );
        $this->attributeTypeFactoryMock =
            $this->createPartialMock(\Magento\Catalog\Api\Data\ProductAttributeTypeInterfaceFactory::class, [
                    'create',
                ]);

        $this->dataObjectHelperMock = $this->getMockBuilder(\Magento\Framework\Api\DataObjectHelper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->model = new TypesList(
            $this->inputTypeFactoryMock,
            $this->attributeTypeFactoryMock,
            $this->dataObjectHelperMock
        );
    }

    public function testGetItems()
    {
        $inputTypeMock = $this->createMock(\Magento\Catalog\Model\Product\Attribute\Source\Inputtype::class);
        $this->inputTypeFactoryMock->expects($this->once())->method('create')->willReturn($inputTypeMock);
        $inputTypeMock->expects($this->once())->method('toOptionArray')->willReturn(['option' => ['value']]);
        $attributeTypeMock = $this->createMock(\Magento\Catalog\Api\Data\ProductAttributeTypeInterface::class);
        $this->dataObjectHelperMock->expects($this->once())
            ->method('populateWithArray')
            ->with($attributeTypeMock, ['value'], \Magento\Catalog\Api\Data\ProductAttributeTypeInterface::class)
            ->willReturnSelf();
        $this->attributeTypeFactoryMock->expects($this->once())->method('create')->willReturn($attributeTypeMock);
        $this->assertEquals([$attributeTypeMock], $this->model->getItems());
    }
}
