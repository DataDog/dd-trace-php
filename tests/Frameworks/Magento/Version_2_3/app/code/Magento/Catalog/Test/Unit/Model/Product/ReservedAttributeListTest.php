<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Model\Product;

use Magento\Catalog\Model\Product\ReservedAttributeList;

class ReservedAttributeListTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ReservedAttributeList
     */
    protected $model;

    protected function setUp(): void
    {
        $this->model = new ReservedAttributeList(
            \Magento\Catalog\Model\Product::class,
            ['some_value'],
            ['some_attribute']
        );
    }

    /**
     * @covers \Magento\Catalog\Model\Product\ReservedAttributeList::isReservedAttribute
     * @dataProvider dataProvider
     */
    public function testIsReservedAttribute($isUserDefined, $attributeCode, $expected)
    {
        $attribute = $this->createPartialMock(
            \Magento\Catalog\Model\Entity\Attribute::class,
            ['getIsUserDefined', 'getAttributeCode', '__sleep', '__wakeup']
        );

        $attribute->expects($this->once())->method('getIsUserDefined')->willReturn($isUserDefined);
        $attribute->expects($this->any())->method('getAttributeCode')->willReturn($attributeCode);

        $this->assertEquals($expected, $this->model->isReservedAttribute($attribute));
    }

    /**
     * @return array
     */
    public function dataProvider()
    {
        return [
            [false, 'some_code', false],
            [true, 'some_value', true],
            [true, 'name', true],
            [true, 'price', true],
            [true, 'category_id', true],
            [true, 'some_code', false],
        ];
    }
}
