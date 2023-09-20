<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Tests for \Magento\Framework\Data\Form\Element\Editor
 */
namespace Magento\Framework\Data\Test\Unit\Form\Element;

use Magento\Framework\Data\Form\Element\Editor;

class EditorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Editor
     */
    protected $model;

    /**
     * @var \Magento\Framework\Data\Form\Element\Factory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $factoryMock;

    /**
     * @var \Magento\Framework\Data\Form\Element\CollectionFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $collectionFactoryMock;

    /**
     * @var \Magento\Framework\Escaper|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $escaperMock;

    /**
     * @var \Magento\Framework\DataObject|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $formMock;

    /**
     * @var \Magento\Framework\DataObject|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $configMock;

    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManager;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $serializer;

    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->factoryMock = $this->createMock(\Magento\Framework\Data\Form\Element\Factory::class);
        $this->collectionFactoryMock = $this->createMock(\Magento\Framework\Data\Form\Element\CollectionFactory::class);
        $this->escaperMock = $this->createMock(\Magento\Framework\Escaper::class);
        $this->configMock = $this->createPartialMock(\Magento\Framework\DataObject::class, ['getData']);

        $this->serializer = $this->createMock(\Magento\Framework\Serialize\Serializer\Json::class);

        $this->model = $this->objectManager->getObject(
            \Magento\Framework\Data\Form\Element\Editor::class,
            [
                'factoryElement' => $this->factoryMock,
                'factoryCollection' => $this->collectionFactoryMock,
                'escaper' => $this->escaperMock,
                'data' => ['config' => $this->configMock],
                'serializer' => $this->serializer
            ]
        );

        $this->formMock =
            $this->createPartialMock(\Magento\Framework\Data\Form::class, ['getHtmlIdPrefix', 'getHtmlIdSuffix']);
        $this->model->setForm($this->formMock);
    }

    public function testConstruct()
    {
        $this->assertEquals('textarea', $this->model->getType());
        $this->assertEquals('textarea', $this->model->getExtType());
        $this->assertEquals(Editor::DEFAULT_ROWS, $this->model->getRows());
        $this->assertEquals(Editor::DEFAULT_COLS, $this->model->getCols());

        $this->configMock->expects($this->once())->method('getData')->with('enabled')->willReturn(true);

        $model = $this->objectManager->getObject(
            \Magento\Framework\Data\Form\Element\Editor::class,
            [
                'factoryElement' => $this->factoryMock,
                'factoryCollection' => $this->collectionFactoryMock,
                'escaper' => $this->escaperMock,
                'data' => ['config' => $this->configMock]
            ]
        );

        $this->assertEquals('wysiwyg', $model->getType());
        $this->assertEquals('wysiwyg', $model->getExtType());
    }

    public function testGetElementHtml()
    {
        $html = $this->model->getElementHtml();
        $this->assertStringContainsString('</textarea>', $html);
        $this->assertStringContainsString('rows="2"', $html);
        $this->assertStringContainsString('cols="15"', $html);
        $this->assertMatchesRegularExpression('/class=\".*textarea.*\"/i', $html);
        $this->assertDoesNotMatchRegularExpression('/.*mage\/adminhtml\/wysiwyg\/widget.*/i', $html);

        $this->configMock->expects($this->any())->method('getData')
            ->willReturnMap(
                [
                    ['enabled', null, true],
                    ['hidden', null, null]
                ]
            );
        $html = $this->model->getElementHtml();
        $this->assertMatchesRegularExpression('/.*mage\/adminhtml\/wysiwyg\/widget.*/i', $html);

        $this->configMock->expects($this->any())->method('getData')
            ->willReturnMap(
                [
                    ['enabled', null, null],
                    ['widget_window_url', null, 'localhost'],
                    ['add_widgets', null, true],
                    ['hidden', null, null]
                ]
            );
        $html = $this->model->getElementHtml();
        $this->assertMatchesRegularExpression('/.*mage\/adminhtml\/wysiwyg\/widget.*/i', $html);
    }

    /**
     * @param bool $expected
     * @param bool $globalFlag
     * @param bool $attributeFlag
     * @dataProvider isEnabledDataProvider
     * @return void
     */
    public function testIsEnabled($expected, $globalFlag, $attributeFlag = null)
    {
        $this->configMock
            ->expects($this->once())
            ->method('getData')
            ->with('enabled')
            ->willReturn($globalFlag);

        if ($attributeFlag !== null) {
            $this->model->setData('wysiwyg', $attributeFlag);
        }
        $this->assertEquals($expected, $this->model->isEnabled());
    }

    /**
     * @return array
     */
    public function isEnabledDataProvider()
    {
        return [
            'Global disabled, attribute isnt set' => [false, false],
            'Global disabled, attribute disabled' => [false, false, false],
            'Global disabled, attribute enabled' => [false, false, true],

            'Global enabled, attribute isnt set' => [true, true],
            'Global enabled, attribute disabled' => [false, true, false],
            'Global enabled, attribute enabled' => [true, true, true]
        ];
    }

    public function testIsHidden()
    {
        $this->assertEmpty($this->model->isHidden());

        $this->configMock->expects($this->once())->method('getData')->with('hidden')->willReturn(true);
        $this->assertTrue($this->model->isHidden());
    }

    public function testTranslate()
    {
        $this->assertEquals('Insert Image...', $this->model->translate('Insert Image...'));
    }

    public function testGetConfig()
    {
        $config = $this->createPartialMock(\Magento\Framework\DataObject::class, ['getData']);
        $this->assertEquals($config, $this->model->getConfig());

        $this->configMock->expects($this->once())->method('getData')->with('test')->willReturn('test');
        $this->assertEquals('test', $this->model->getConfig('test'));
    }

    /**
     * Test protected `getTranslatedString` method via public `getElementHtml` method
     */
    public function testGetTranslatedString()
    {
        $callback = function ($params) {
            return json_encode($params);
        };

        $this->configMock->expects($this->any())->method('getData')->withConsecutive(['enabled'])->willReturn(true);
        $this->serializer->expects($this->any())
            ->method('serialize')
            ->willReturnCallback($callback);

        $html = $this->model->getElementHtml();

        $this->assertMatchesRegularExpression('/.*"Insert Image...":"Insert Image...".*/i', $html);
    }
}
