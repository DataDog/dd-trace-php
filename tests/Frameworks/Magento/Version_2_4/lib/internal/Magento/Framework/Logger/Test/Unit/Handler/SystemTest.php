<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Logger\Test\Unit\Handler;

use DateTime;
use Exception;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Logger\Handler\Exception as ExceptionHandler;
use Magento\Framework\Logger\Handler\System;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject as Mock;
use PHPUnit\Framework\TestCase;

class SystemTest extends TestCase
{
    /**
     * @var System
     */
    private $model;

    /**
     * @var DriverInterface|Mock
     */
    private $filesystemMock;

    /**
     * @var ExceptionHandler|Mock
     */
    private $exceptionHandlerMock;

    /**
     * @inheritdoc
     *
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->filesystemMock = $this->getMockBuilder(DriverInterface::class)
            ->getMockForAbstractClass();
        $this->exceptionHandlerMock = $this->getMockBuilder(
            ExceptionHandler::class
        )->disableOriginalConstructor()->getMock();

        $this->model = new System(
            $this->filesystemMock,
            $this->exceptionHandlerMock
        );
    }

    public function testWrite()
    {
        $this->filesystemMock->expects($this->once())
            ->method('getParentDirectory');
        $this->filesystemMock->expects($this->once())
            ->method('isDirectory')
            ->willReturn('true');

        $this->model->write($this->getRecord());
    }

    public function testWriteException()
    {
        $record = $this->getRecord();
        $record['context']['exception'] = new Exception('Some exception');

        $this->exceptionHandlerMock->expects($this->once())
            ->method('handle')
            ->with($record);
        $this->filesystemMock->expects($this->never())
            ->method('getParentDirectory');

        $this->model->write($record);
    }

    /**
     * @param int $level
     * @param string $message
     * @param array $context
     * @return array
     */
    private function getRecord(
        int $level = Logger::WARNING,
        string $message = 'test',
        array $context = []
    ): array {
        return [
            'message' => $message,
            'context' => $context,
            'level' => $level,
            'level_name' => Logger::getLevelName($level),
            'channel' => 'test',
            'datetime' => DateTime::createFromFormat(
                'U.u',
                sprintf('%.6F', microtime(true))
            ),
            'extra' => [],
        ];
    }
}
