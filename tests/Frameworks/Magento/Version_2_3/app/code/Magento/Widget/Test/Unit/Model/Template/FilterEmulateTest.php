<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Widget\Test\Unit\Model\Template;

use \Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

class FilterEmulateTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ObjectManagerHelper
     */
    protected $objectManagerHelper;

    /**
     * @var \Magento\Widget\Model\Template\FilterEmulate
     */
    protected $filterEmulate;

    /**
     * @var \Magento\Framework\App\State|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $appStateMock;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->appStateMock = $this->createMock(\Magento\Framework\App\State::class);

        $this->filterEmulate = $this->objectManagerHelper->getObject(
            \Magento\Widget\Model\Template\FilterEmulate::class,
            ['appState' => $this->appStateMock]
        );
    }

    /**
     * @return void
     */
    public function testWidgetDirective()
    {
        $result = 'some text';
        $construction = [
            '{{widget type="Widget\\Link" anchor_text="Test" template="block.phtml" id_path="p/1"}}',
            'widget',
            ' type="" anchor_text="Test" template="block.phtml" id_path="p/1"'
        ];

        $this->appStateMock->expects($this->once())
            ->method('emulateAreaCode')
            ->with('frontend', [$this->filterEmulate, 'generateWidget'], [$construction])
            ->willReturn($result);
        $this->assertSame($result, $this->filterEmulate->widgetDirective($construction));
    }
}
