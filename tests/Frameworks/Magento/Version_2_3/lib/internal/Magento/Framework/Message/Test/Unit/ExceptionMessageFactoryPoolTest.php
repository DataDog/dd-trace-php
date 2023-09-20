<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Message\Test\Unit;

use Magento\Framework\Message\ExceptionMessageFactoryInterface;
use Magento\Framework\Exception\LocalizedException;

class ExceptionMessageFactoryPoolTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ExceptionMessageFactoryInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    private $defaultExceptionMessageFactoryMock;

    /**
     * @var \Magento\Framework\Message\ExceptionMessageFactoryInterface
     */
    private $specificExceptionMessageFactoryMock;

    /**
     * @var ExceptionMessageFactoryInterface[] | \PHPUnit\Framework\MockObject\MockObject
     */
    private $exceptionMessageFactoryMapMock;

    /**
     * @var \Magento\Framework\Message\ExceptionMessageFactoryPool
     */
    private $exceptionMessageFactoryPool;

    protected function setUp(): void
    {
        $this->specificExceptionMessageFactoryMock = $this->getMockForAbstractClass(ExceptionMessageFactoryInterface::class);
        $this->defaultExceptionMessageFactoryMock = $this->getMockForAbstractClass(ExceptionMessageFactoryInterface::class);

        $this->exceptionMessageFactoryMapMock = [
            \Magento\Framework\Exception\LocalizedException::class => $this->specificExceptionMessageFactoryMock
        ];
        $this->exceptionMessageFactoryPool = new \Magento\Framework\Message\ExceptionMessageFactoryPool(
            $this->defaultExceptionMessageFactoryMock,
            $this->exceptionMessageFactoryMapMock
        );
    }

    public function testSuccessfulDefaultCreateMessage()
    {
        $exception = new \Exception('message');
        $this->assertEquals(
            $this->defaultExceptionMessageFactoryMock,
            $this->exceptionMessageFactoryPool->getMessageFactory($exception)
        );
    }

    public function testSuccessfulSpecificCreateMessage()
    {
        $localizedException = new LocalizedException(__('message'));
        $this->assertEquals(
            $this->specificExceptionMessageFactoryMock,
            $this->exceptionMessageFactoryPool->getMessageFactory($localizedException)
        );
    }
}
