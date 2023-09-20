<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Rule\Test\Unit\Model\Renderer;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

class ActionsTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Rule\Model\Renderer\Actions
     */
    protected $actions;

    /**
     * @var ObjectManagerHelper
     */
    protected $objectManagerHelper;

    /**
     * @var \Magento\Framework\Data\Form\Element\AbstractElement|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_element;

    protected function setUp(): void
    {
        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->actions = $this->objectManagerHelper->getObject(\Magento\Rule\Model\Renderer\Actions::class);
        $this->_element = $this->createPartialMock(
            \Magento\Framework\Data\Form\Element\AbstractElement::class,
            ['getRule']
        );
    }

    public function testRender()
    {
        $rule = $this->getMockBuilder(\Magento\Rule\Model\AbstractModel::class)
            ->setMethods(['getActions', '__sleep', '__wakeup'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $actions = $this->createPartialMock(\Magento\Rule\Model\Action\Collection::class, ['asHtmlRecursive']);

        $this->_element->expects($this->any())
            ->method('getRule')
            ->willReturn($rule);

        $rule->expects($this->any())
            ->method('getActions')
            ->willReturn($actions);

        $actions->expects($this->once())
            ->method('asHtmlRecursive')
            ->willReturn('action html');

        $this->assertEquals('action html', $this->actions->render($this->_element));
    }
}
