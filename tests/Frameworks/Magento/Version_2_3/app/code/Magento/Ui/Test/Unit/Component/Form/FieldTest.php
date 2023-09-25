<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Ui\Test\Unit\Component\Form;

use Magento\Ui\Component\Form\Field;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Framework\View\Element\UiComponentInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;

/**
 * Class FieldTest
 *
 * Test for class \Magento\Ui\Component\Form\Field
 */
class FieldTest extends \PHPUnit\Framework\TestCase
{
    const NAME = 'test-name';
    const COMPONENT_NAME = 'test-name';
    const COMPONENT_NAMESPACE = 'test-name';

    /**
     * @var Field
     */
    protected $field;

    /**
     * @var UiComponentFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $uiComponentFactoryMock;

    /**
     * @var ContextInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $contextMock;

    /**
     * @var array
     */
    protected $testConfigData = [
        ['config', null, ['test-key' => 'test-value']],
        ['js_config', null, ['test-key' => 'test-value']]
    ];

    /**
     * Set up
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->uiComponentFactoryMock = $this->getMockBuilder(\Magento\Framework\View\Element\UiComponentFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->contextMock = $this->getMockBuilder(\Magento\Framework\View\Element\UiComponent\ContextInterface::class)
            ->getMockForAbstractClass();

        $this->field = new Field(
            $this->contextMock,
            $this->uiComponentFactoryMock
        );
    }

    /**
     * Run test for prepare method
     *
     * @param array $data
     * @param array $expectedData
     * @return void
     *
     * @dataProvider prepareSuccessDataProvider
     */
    public function testPrepareSuccess(array $data, array $expectedData)
    {
        $processor = $this->getMockBuilder(\Magento\Framework\View\Element\UiComponent\Processor::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->contextMock->expects($this->atLeastOnce())->method('getProcessor')->willReturn($processor);
        $this->uiComponentFactoryMock->expects($this->once())
            ->method('create')
            ->with(self::NAME, $data['config']['formElement'], $this->arrayHasKey('context'))
            ->willReturn($this->getWrappedComponentMock());

        $this->contextMock->expects($this->any())
            ->method('getNamespace')
            ->willReturn(self::COMPONENT_NAMESPACE);

        $this->field->setData($data);
        $this->field->prepare();
        $result = $this->field->getData();

        $this->assertEquals($expectedData, $result);
    }

    /**
     * Data provider for testPrepare
     *
     * @return array
     */
    public function prepareSuccessDataProvider()
    {
        return [
            [
                'data' => [
                    'name' => self::NAME,
                    'config' => [
                        'formElement' => 'test',
                    ]
                ],
                'expectedData' => [
                    'name' => self::NAME,
                    'config' => [
                        'test-key' => 'test-value',
                    ],
                    'js_config' => [
                        'extends' => self::NAME,
                        'test-key' => 'test-value',
                    ]
                ]
            ],
        ];
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|UiComponentInterface
     */
    protected function getWrappedComponentMock()
    {
        $wrappedComponentMock = $this->getMockBuilder(\Magento\Framework\View\Element\UiComponentInterface::class)
            ->getMockForAbstractClass();

        $wrappedComponentMock->expects($this->any())
            ->method('getData')
            ->willReturnMap($this->testConfigData);
        $wrappedComponentMock->expects($this->once())
            ->method('setData')
            ->with('config', $this->logicalNot($this->isEmpty()));
        $wrappedComponentMock->expects($this->once())
            ->method('prepare');
        $wrappedComponentMock->expects($this->atLeastOnce())
            ->method('getChildComponents')
            ->willReturn($this->getComponentsMock());
        $wrappedComponentMock->expects($this->any())
            ->method('getComponentName')
            ->willReturn(self::COMPONENT_NAME);
        $wrappedComponentMock->expects($this->once())
            ->method('getContext')
            ->willReturn($this->contextMock);

        return $wrappedComponentMock;
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject[]|UiComponentInterface[]
     */
    protected function getComponentsMock()
    {
        $componentMock = $this->getMockBuilder(\Magento\Framework\View\Element\UiComponentInterface::class)
            ->getMockForAbstractClass();

        return [$componentMock];
    }

    /**
     * Run test prepare method (Exception)
     *
     * @return void
     *
     */
    public function testPrepareException()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('The "formElement" configuration parameter is required for the "test-name" field.');

        $this->contextMock->expects($this->never())->method('getProcessor');
        $this->uiComponentFactoryMock->expects($this->never())
            ->method('create');
        $this->field->setData(['name' => self::NAME]);
        $this->field->prepare();
    }
}
