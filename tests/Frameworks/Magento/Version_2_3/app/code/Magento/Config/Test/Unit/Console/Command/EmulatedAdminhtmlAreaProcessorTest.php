<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Config\Test\Unit\Console\Command;

use Magento\Config\Console\Command\EmulatedAdminhtmlAreaProcessor;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Config\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject as MockObject;

class EmulatedAdminhtmlAreaProcessorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * The application scope manager.
     *
     * @var ScopeInterface|MockObject
     */
    private $scopeMock;

    /**
     * The application state manager.
     *
     * @var State|MockObject
     */
    private $stateMock;

    /**
     * Emulator adminhtml area for CLI command.
     *
     * @var EmulatedAdminhtmlAreaProcessor
     */
    private $emulatedAdminhtmlProcessorArea;

    protected function setUp(): void
    {
        $this->scopeMock = $this->getMockBuilder(ScopeInterface::class)
            ->getMockForAbstractClass();
        $this->stateMock = $this->getMockBuilder(State::class)
            ->disableOriginalConstructor()
            ->setMethods(['emulateAreaCode'])
            ->getMock();

        $this->emulatedAdminhtmlProcessorArea = new EmulatedAdminhtmlAreaProcessor(
            $this->scopeMock,
            $this->stateMock
        );
    }

    public function testProcess()
    {
        $currentScope = 'currentScope';
        $callback = function () {
        };
        $this->scopeMock->expects($this->once())
            ->method('getCurrentScope')
            ->willReturn($currentScope);

        $this->scopeMock->expects($this->once())
            ->method('setCurrentScope')
            ->with($currentScope);

        $this->stateMock->expects($this->once())
            ->method('emulateAreaCode')
            ->with(Area::AREA_ADMINHTML, $callback)
            ->willReturn('result');

        $this->assertEquals('result', $this->emulatedAdminhtmlProcessorArea->process($callback));
    }

    /**
     */
    public function testProcessWithException()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Some Message');

        $currentScope = 'currentScope';
        $this->scopeMock->expects($this->once())
            ->method('getCurrentScope')
            ->willReturn($currentScope);

        $this->scopeMock->expects($this->once())
            ->method('setCurrentScope')
            ->with($currentScope);

        $this->stateMock->expects($this->once())
            ->method('emulateAreaCode')
            ->willThrowException(new \Exception('Some Message'));

        $this->emulatedAdminhtmlProcessorArea->process(function () {
        });
    }
}
