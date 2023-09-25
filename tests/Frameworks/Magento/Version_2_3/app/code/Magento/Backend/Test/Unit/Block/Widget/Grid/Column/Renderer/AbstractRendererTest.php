<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Test\Unit\Block\Widget\Grid\Column\Renderer;

class AbstractRendererTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Backend\Block\Widget\Grid\Column|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $columnMock;

    /**
     * @var \Magento\Framework\DataObject|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $dataObjectMock;

    /**
     * @var \Magento\Backend\Block\Widget\Grid\Column\Renderer\AbstractRenderer
     */
    protected $renderer;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->dataObjectMock = $this->createPartialMock(\Magento\Framework\DataObject::class, ['getData']);
        $this->columnMock = $this->getMockBuilder(\Magento\Backend\Block\Widget\Grid\Column::class)
            ->disableOriginalConstructor()
            ->setMethods(['getEditable', 'getIndex', 'getEditOnly', 'getId'])
            ->getMock();
        $this->renderer =
            $this->getMockBuilder(\Magento\Backend\Block\Widget\Grid\Column\Renderer\AbstractRenderer::class)
            ->disableOriginalConstructor()
            ->setMethods(null)
            ->getMock();
    }

    /**
     * @param bool $editable
     * @param bool $onlyEdit
     * @param string $expectedResult
     * @return void
     * @dataProvider renderDataProvider
     */
    public function testRender($editable, $onlyEdit, $expectedResult)
    {
        $value = 'some value';
        $keyValue = 'key';

        $this->columnMock->expects($this->once())
            ->method('getEditable')
            ->willReturn($editable);
        $this->columnMock->expects($this->any())
            ->method('getEditOnly')
            ->willReturn($onlyEdit);
        $this->columnMock->expects($this->any())
            ->method('getIndex')
            ->willReturn($keyValue);
        $this->columnMock->expects($this->any())
            ->method('getId')
            ->willReturn('test');
        $this->dataObjectMock->expects($this->any())
            ->method('getData')
            ->with($keyValue)
            ->willReturn($value);
        $this->renderer->setColumn($this->columnMock);

        $this->assertEquals($expectedResult, $this->renderer->render($this->dataObjectMock));
    }

    /**
     * @return array
     */
    public function renderDataProvider()
    {
        return [
            [
                'editable' => false,
                'onlyEdit' => false,
                'expectedResult' => 'some value'
            ],
            [
                'editable' => false,
                'onlyEdit' => true,
                'expectedResult' => 'some value'
            ],
            [
                'editable' => true,
                'onlyEdit' => false,
                'expectedResult' => '<div class="admin__grid-control">'
                    . '<span class="admin__grid-control-value">some value</span>'
                    . '<input type="text" class="input-text " name="test" value="some value"/>'
                    . '</div>'
            ],
            [
                'editable' => true,
                'onlyEdit' => true,
                'expectedResult' => '<div class="admin__grid-control">'
                    . '<input type="text" class="input-text " name="test" value="some value"/>'
                    . '</div>'
            ],
        ];
    }
}
