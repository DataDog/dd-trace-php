<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Message\Test\Unit;

use Magento\Framework\Message\MessageInterface;

class ExceptionMessageLookupFactoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Message\ExceptionMessageFactoryPool | \PHPUnit\Framework\MockObject\MockObject
     */
    private $exceptionMessageFactoryPool;

    /**
     * @var \Magento\Framework\Message\Factory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $messageFactory;

    /**
     * @var \Magento\Framework\Message\ExceptionMessageLookupFactory
     */
    private $exceptionMessageLookupFactory;

    protected function setUp(): void
    {
        $this->exceptionMessageFactoryPool = $this->createPartialMock(
            \Magento\Framework\Message\ExceptionMessageFactoryPool::class,
            ['getMessageFactory']
        );

        $this->messageFactory = $this->getMockBuilder(
            \Magento\Framework\Message\Factory::class
        )
            ->disableOriginalConstructor()
            ->getMock();

        $this->exceptionMessageLookupFactory = new \Magento\Framework\Message\ExceptionMessageLookupFactory(
            $this->exceptionMessageFactoryPool
        );
    }

    public function testCreateMessage()
    {
        $exceptionMessage = 'exception message';
        $exception = new \Exception($exceptionMessage);

        $exceptionMessageFactory = $this->createMock(
            \Magento\Framework\Message\ExceptionMessageFactoryInterface::class
        );

        $this->exceptionMessageFactoryPool->expects(
            $this->once()
        )->method(
            'getMessageFactory'
        )->with(
            $exception
        )->willReturn(
            $exceptionMessageFactory
        );

        $messageError = $this->getMockBuilder(
            \Magento\Framework\Message\Error::class
        )->getMock();

        $this->messageFactory->expects($this->never())
            ->method('create');

        $exceptionMessageFactory->expects($this->once())
            ->method('createMessage')
            ->with($exception, MessageInterface::TYPE_ERROR)
            ->willReturn($messageError);

        $this->assertEquals($messageError, $this->exceptionMessageLookupFactory->createMessage($exception));
    }
}
