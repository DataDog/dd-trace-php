<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\View\Test\Unit\Layout;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

class ReaderPoolTest extends \PHPUnit\Framework\TestCase
{
    /** @var ObjectManagerHelper */
    protected $objectManagerHelper;

    /** @var \Magento\Framework\View\Layout\ReaderPool */
    protected $pool;

    /** @var \Magento\Framework\View\Layout\ReaderFactory|\PHPUnit\Framework\MockObject\MockObject */
    protected $readerFactoryMock;

    protected function setUp(): void
    {
        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->readerFactoryMock = $this->getMockBuilder(\Magento\Framework\View\Layout\ReaderFactory::class)
            ->disableOriginalConstructor()->getMock();

        $this->pool = $this->objectManagerHelper->getObject(
            \Magento\Framework\View\Layout\ReaderPool::class,
            [
                'readerFactory' => $this->readerFactoryMock,
                'readers' => ['move' => \Magento\Framework\View\Layout\Reader\Move::class]
            ]
        );
    }

    public function testInterpret()
    {
        /** @var Reader\Context $contextMock */
        $contextMock = $this->getMockBuilder(\Magento\Framework\View\Layout\Reader\Context::class)
            ->disableOriginalConstructor()->getMock();

        $currentElement = new \Magento\Framework\View\Layout\Element(
            '<element><move name="block"/><remove name="container"/><ignored name="user"/></element>'
        );

        /**
         * @var \Magento\Framework\View\Layout\Reader\Move|\PHPUnit\Framework\MockObject\MockObject $moveReaderMock
         */
        $moveReaderMock = $this->getMockBuilder(\Magento\Framework\View\Layout\Reader\Move::class)
            ->disableOriginalConstructor()->getMock();
        $moveReaderMock->expects($this->exactly(2))->method('interpret')
            ->willReturn($this->returnSelf());
        $moveReaderMock->method('getSupportedNodes')
            ->willReturn(['move']);

        $this->readerFactoryMock->expects($this->once())
            ->method('create')
            ->willReturnMap([[\Magento\Framework\View\Layout\Reader\Move::class, [], $moveReaderMock]]);

        $this->pool->interpret($contextMock, $currentElement);
        $this->pool->interpret($contextMock, $currentElement);
    }
}
