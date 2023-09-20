<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Test class for \Magento\Backend\Block\Widget\Button
 */
namespace Magento\Backend\Test\Unit\Block\Widget;

class ButtonTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_layoutMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_factoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_blockMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_buttonMock;

    protected function setUp(): void
    {
        $this->_layoutMock = $this->createMock(\Magento\Framework\View\Layout::class);

        $arguments = [
            'urlBuilder' => $this->createMock(\Magento\Backend\Model\Url::class),
            'layout' => $this->_layoutMock,
        ];

        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->_blockMock = $objectManagerHelper->getObject(\Magento\Backend\Block\Widget\Button::class, $arguments);
    }

    protected function tearDown(): void
    {
        unset($this->_layoutMock);
        unset($this->_buttonMock);
    }

    /**
     * @covers \Magento\Backend\Block\Widget\Button::getAttributesHtml
     * @dataProvider getAttributesHtmlDataProvider
     */
    public function testGetAttributesHtml($data, $expect)
    {
        $this->_blockMock->setData($data);
        $attributes = $this->_blockMock->getAttributesHtml();
        $this->assertMatchesRegularExpression($expect, $attributes);
    }

    /**
     * @return array
     */
    public function getAttributesHtmlDataProvider()
    {
        return [
            [
                ['data_attribute' => ['validation' => ['required' => true]]],
                '/data-validation="[^"]*" /',
            ],
            [
                ['data_attribute' => ['mage-init' => ['button' => ['someKey' => 'someValue']]]],
                '/data-mage-init="[^"]*" /'
            ],
            [
                [
                    'data_attribute' => [
                        'mage-init' => ['button' => ['someKey' => 'someValue']],
                        'validation' => ['required' => true],
                    ],
                ],
                '/data-mage-init="[^"]*" data-validation="[^"]*" /'
            ]
        ];
    }
}
