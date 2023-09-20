<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Theme\Test\Unit\Ui\Component\Design\Config\SearchRobots;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Theme\Ui\Component\Design\Config\SearchRobots\ResetButton;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Ui\Component\Form\Field;

class ResetButtonTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | ContextInterface
     */
    private $contextMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | UiComponentFactory
     */
    private $componentFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | ScopeConfigInterface
     */
    private $scopeConfigMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject |
     */
    private $processorMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject |
     */
    private $wrappingComponentMock;

    /**
     * @var ResetButton
     */
    private $resetButton;

    protected function setUp(): void
    {
        $this->contextMock = $this->getMockBuilder(ContextInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->componentFactoryMock = $this->getMockBuilder(UiComponentFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->scopeConfigMock = $this->getMockBuilder(ScopeConfigInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->processorMock = $this->getMockBuilder(Processor::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->contextMock->expects($this->atLeastOnce())
            ->method("getProcessor")
            ->willReturn($this->processorMock);
        $this->wrappingComponentMock = $this->getMockBuilder(Field::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->resetButton = new ResetButton(
            $this->contextMock,
            $this->componentFactoryMock,
            [],
            [
                'config' => [
                    'formElement' => 'button'
                ]
            ],
            $this->scopeConfigMock
        );
    }
    
    public function testPrepare()
    {
        $robotsContent = "Content";

        $this->componentFactoryMock->expects($this->once())
            ->method("create")
            ->willReturn($this->wrappingComponentMock);
        $this->wrappingComponentMock->expects($this->once())
            ->method("getContext")
            ->willReturn($this->contextMock);
        $this->scopeConfigMock->expects($this->once())
            ->method("getValue")
            ->willReturn($robotsContent);

        $this->resetButton->prepare();
        $actions = $this->resetButton->getData("config/actions");
        $this->assertEquals(json_encode($robotsContent), $actions[0]["params"][0]);
    }
}
