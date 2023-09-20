<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Analytics\Test\Unit\ReportXml;

use Magento\Analytics\ReportXml\IteratorFactory;
use Magento\Framework\ObjectManagerInterface;

class IteratorFactoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectManagerMock;

    /**
     * @var \IteratorIterator|\PHPUnit\Framework\MockObject\MockObject
     */
    private $iteratorIteratorMock;

    /**
     * @var IteratorFactory
     */
    private $iteratorFactory;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectManagerMock = $this->getMockBuilder(ObjectManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->iteratorIteratorMock = $this->getMockBuilder(\IteratorIterator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->iteratorFactory = new IteratorFactory(
            $this->objectManagerMock
        );
    }

    public function testCreate()
    {
        $arrayObject = new \ArrayIterator([1, 2, 3, 4, 5]);
        $this->objectManagerMock->expects($this->once())
            ->method('create')
            ->with(\IteratorIterator::class, ['iterator' => $arrayObject])
            ->willReturn($this->iteratorIteratorMock);

        $this->assertEquals($this->iteratorFactory->create($arrayObject), $this->iteratorIteratorMock);
    }
}
