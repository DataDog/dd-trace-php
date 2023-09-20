<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Bundle\Test\Unit\Model\Product\Attribute\Source\Price;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class ViewTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Bundle\Model\Product\Attribute\Source\Price\View
     */
    protected $model;

    /**
     * @var \Magento\Eav\Model\ResourceModel\Entity\Attribute\Option|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $option;

    /**
     * @var \Magento\Eav\Model\ResourceModel\Entity\Attribute\OptionFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $optionFactory;

    /**
     * @var \Magento\Eav\Model\Entity\Attribute\AbstractAttribute|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $attribute;

    protected function setUp(): void
    {
        $this->option = $this->createMock(\Magento\Eav\Model\ResourceModel\Entity\Attribute\Option::class);
        $this->optionFactory = $this->createPartialMock(
            \Magento\Eav\Model\ResourceModel\Entity\Attribute\OptionFactory::class,
            ['create']
        );
        $this->optionFactory->expects($this->any())
            ->method('create')
            ->willReturn($this->option);
        $this->attribute = $this->createMock(\Magento\Eav\Model\Entity\Attribute\AbstractAttribute::class);

        $this->model = (new ObjectManager($this))
            ->getObject(
                \Magento\Bundle\Model\Product\Attribute\Source\Price\View::class,
                [
                    'optionFactory' => $this->optionFactory,
                ]
            );
        $this->model->setAttribute($this->attribute);
    }

    public function testGetAllOptions()
    {
        $options = $this->model->getAllOptions();

        $this->assertIsArray($options);
        $this->assertNotEmpty($options);

        foreach ($options as $option) {
            $this->assertArrayHasKey('label', $option);
            $this->assertArrayHasKey('value', $option);
        }
    }

    /**
     * @covers \Magento\Bundle\Model\Product\Attribute\Source\Price\View::getOptionText
     */
    public function testGetOptionTextForExistLabel()
    {
        $existValue = 1;

        $this->assertInstanceOf(\Magento\Framework\Phrase::class, $this->model->getOptionText($existValue));
    }

    /**
     * @covers \Magento\Bundle\Model\Product\Attribute\Source\Price\View::getOptionText
     */
    public function testGetOptionTextForNotExistLabel()
    {
        $notExistValue = -1;

        $this->assertFalse($this->model->getOptionText($notExistValue));
    }

    public function testGetFlatColumns()
    {
        $code = 'attribute-code';
        $this->attribute->expects($this->any())
            ->method('getAttributeCode')
            ->willReturn($code);

        $columns = $this->model->getFlatColumns();

        $this->assertIsArray($columns);
        $this->assertArrayHasKey($code, $columns);

        foreach ($columns as $column) {
            $this->assertArrayHasKey('unsigned', $column);
            $this->assertArrayHasKey('default', $column);
            $this->assertArrayHasKey('extra', $column);
            $this->assertArrayHasKey('type', $column);
            $this->assertArrayHasKey('nullable', $column);
            $this->assertArrayHasKey('comment', $column);
        }
    }

    public function testGetFlatUpdateSelect()
    {
        $store = 1;
        $select = 'select';

        $this->option->expects($this->once())
            ->method('getFlatUpdateSelect')
            ->with($this->attribute, $store, false)
            ->willReturn($select);

        $this->assertEquals($select, $this->model->getFlatUpdateSelect($store));
    }
}
