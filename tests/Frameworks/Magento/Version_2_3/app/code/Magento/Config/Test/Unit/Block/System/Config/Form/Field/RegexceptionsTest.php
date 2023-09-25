<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Tests for \Magento\Framework\Data\Form\Field\Regexceptions
 */
namespace Magento\Config\Test\Unit\Block\System\Config\Form\Field;

class RegexceptionsTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var array
     */
    protected $cellParameters;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $labelFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $labelMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $elementFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $elementMock;

    /**
     * @var \Magento\Config\Block\System\Config\Form\Field\Regexceptions
     */
    protected $object;

    protected function setUp(): void
    {
        $this->cellParameters = [
            'size'  => 'testSize',
            'style' => 'testStyle',
            'class' => 'testClass',
        ];

        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->labelFactoryMock = $this->getMockBuilder(\Magento\Framework\View\Design\Theme\LabelFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->labelMock = $this->getMockBuilder(\Magento\Framework\View\Design\Theme\Label::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->elementFactoryMock = $this->getMockBuilder(\Magento\Framework\Data\Form\Element\Factory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->elementMock = $this->getMockBuilder(\Magento\Framework\Data\Form\Element\AbstractElement::class)
            ->disableOriginalConstructor()
            ->setMethods(
                ['setForm', 'setName', 'setHtmlId', 'setValues', 'getName', 'getHtmlId', 'getValues', 'getElementHtml']
            )
            ->getMock();

        $data = [
            'elementFactory' => $this->elementFactoryMock,
            'labelFactory'   => $this->labelFactoryMock,
            'data'           => [
                'element' => $this->elementMock
            ],
        ];
        $this->object = $objectManager->getObject(
            \Magento\Config\Block\System\Config\Form\Field\Regexceptions::class,
            $data
        );
    }

    public function testRenderCellTemplateValueColumn()
    {
        $columnName     = 'value';
        $expectedResult = 'testValueElementHtml';

        $this->elementFactoryMock->expects($this->once())->method('create')->willReturn($this->elementMock);
        $this->elementMock->expects($this->once())->method('setForm')->willReturnSelf();
        $this->elementMock->expects($this->once())->method('setName')->willReturnSelf();
        $this->elementMock->expects($this->once())->method('setHtmlId')->willReturnSelf();
        $this->elementMock->expects($this->once())->method('setValues')->willReturnSelf();
        $this->elementMock->expects($this->once())->method('getElementHtml')->willReturn($expectedResult);

        $this->labelFactoryMock->expects($this->once())->method('create')->willReturn($this->labelMock);
        $this->labelMock->expects($this->once())->method('getLabelsCollection')->willReturn([]);

        $this->object->addColumn(
            $columnName,
            $this->cellParameters
        );

        $this->assertEquals(
            $expectedResult,
            $this->object->renderCellTemplate($columnName)
        );
    }

    public function testRenderCellTemplateOtherColumn()
    {
        $columnName     = 'testCellName';

        $this->object->addColumn(
            $columnName,
            $this->cellParameters
        );

        $actual = $this->object->renderCellTemplate($columnName);
        foreach ($this->cellParameters as $parameter) {
            $this->assertStringContainsString(
                $parameter,
                $actual,
                'Parameter \'' . $parameter . '\' missing in render output.'
            );
        }
    }

    public function testRenderCellTemplateWrongColumnName()
    {
        $columnName      = 'testCellName';
        $wrongColumnName = 'wrongTestCellName';

        $this->object->addColumn($wrongColumnName, $this->cellParameters);

        $this->expectException('\Exception');
        $this->expectExceptionMessage('Wrong column name specified.');

        $this->object->renderCellTemplate($columnName);
    }
}
