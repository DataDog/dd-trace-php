<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Payment\Test\Unit\Model\Method;

use Magento\Payment\Model\Method\Logger;
use Psr\Log\LoggerInterface;

class LoggerTest extends \PHPUnit\Framework\TestCase
{
    /** @var Logger | \PHPUnit\Framework\MockObject\MockObject */
    private $logger;

    /** @var LoggerInterface | \PHPUnit\Framework\MockObject\MockObject */
    private $loggerMock;

    protected function setUp(): void
    {
        $this->loggerMock = $this->getMockForAbstractClass(\Psr\Log\LoggerInterface::class);
        $this->logger = new Logger($this->loggerMock);
    }

    public function testDebugOn()
    {
        $debugData =
            [
                'request' => ['masked' => '123', 'unmasked' => '123']
            ];
        $expectedDebugData =
            [
                'request' => ['masked' => Logger::DEBUG_KEYS_MASK, 'unmasked' => '123']
            ];
        $debugReplaceKeys =
            [
                'masked'
            ];

        $this->loggerMock->expects($this->once())
            ->method('debug')
            ->with(var_export($expectedDebugData, true));

        $this->logger->debug($debugData, $debugReplaceKeys, true);
    }

    public function testDebugOnNoReplaceKeys()
    {
        $debugData =
            [
                'request' => ['data1' => '123', 'data2' => '123']
            ];

        $this->loggerMock->expects(static::once())
            ->method('debug')
            ->with(var_export($debugData, true));

        $this->logger->debug($debugData, [], true);
    }

    public function testDebugOff()
    {
        $debugData =
            [
                'request' => ['masked' => '123', 'unmasked' => '123']
            ];
        $debugReplaceKeys =
            [
                'masked'
            ];

        $this->loggerMock->expects($this->never())
            ->method('debug');

        $this->logger->debug($debugData, $debugReplaceKeys, false);
    }
}
