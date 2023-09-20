<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\DB\Test\Unit\Logger;

use \Magento\Framework\DB\Logger\File;

class FileTest extends \PHPUnit\Framework\TestCase
{
    const DEBUG_FILE = 'debug.file.log';

    /**
     * @var \Magento\Framework\Filesystem\File\WriteInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $stream;

    /**
     * @var \Magento\Framework\Filesystem\Directory\WriteInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $dir;

    /**
     * @var \Magento\Framework\DB\Logger\File
     */
    private $object;

    protected function setUp(): void
    {
        $this->stream = $this->getMockForAbstractClass(\Magento\Framework\Filesystem\File\WriteInterface::class);
        $this->dir = $this->getMockForAbstractClass(\Magento\Framework\Filesystem\Directory\WriteInterface::class);
        $this->dir->expects($this->any())
            ->method('openFile')
            ->with(self::DEBUG_FILE, 'a')
            ->willReturn($this->stream);
        $filesystem = $this->createMock(\Magento\Framework\Filesystem::class);
        $filesystem->expects($this->any())
            ->method('getDirectoryWrite')
            ->willReturn($this->dir);

        $this->object = new File(
            $filesystem,
            self::DEBUG_FILE
        );
    }

    public function testLog()
    {
        $input = 'message';
        $expected = '%amessage';

        $this->stream->expects($this->once())
            ->method('write')
            ->with($this->matches($expected));

        $this->object->log($input);
    }

    /**
     * @param $type
     *
     * @param string $q
     * @param array $bind
     * @param \Zend_Db_Statement_Pdo|null $result
     * @param string $expected
     * @dataProvider logStatsDataProvider
     */
    public function testLogStats($type, $q, array $bind, $result, $expected)
    {
        $this->stream->expects($this->once())
            ->method('write')
            ->with($this->matches($expected));
        $this->object->logStats($type, $q, $bind, $result);
    }

    /**
     * @return array
     */
    public function logStatsDataProvider()
    {
        return [
            [\Magento\Framework\DB\LoggerInterface::TYPE_CONNECT, '', [], null, '%aCONNECT%a'],
            [
                \Magento\Framework\DB\LoggerInterface::TYPE_TRANSACTION,
                'SELECT something',
                [],
                null,
                '%aTRANSACTION SELECT something%a'
            ],
            [
                \Magento\Framework\DB\LoggerInterface::TYPE_QUERY,
                'SELECT something',
                [],
                null,
                '%aSQL: SELECT something%a'
            ],
            [
                \Magento\Framework\DB\LoggerInterface::TYPE_QUERY,
                'SELECT something',
                ['data'],
                null,
                "%aQUERY%aSQL: SELECT something%aBIND: array (%a0 => 'data',%a)%a"
            ],
        ];
    }

    public function testLogStatsWithResult()
    {
        $result = $this->createMock(\Zend_Db_Statement_Pdo::class);
        $result->expects($this->once())
            ->method('rowCount')
            ->willReturn(10);
        $this->stream->expects($this->once())
            ->method('write')
            ->with($this->logicalNot($this->matches('%aSQL: SELECT something%aAFF: 10')));

        $this->object->logStats(
            \Magento\Framework\DB\LoggerInterface::TYPE_QUERY,
            'SELECT something',
            [],
            $result
        );
    }

    public function testLogStatsUnknownType()
    {
        $this->stream->expects($this->once())
            ->method('write')
            ->with($this->logicalNot($this->matches('%aSELECT something%a')));
        $this->object->logStats('unknown', 'SELECT something');
    }

    public function testcritical()
    {
        $exception = new \Exception('error message');
        $expected = "%aEXCEPTION%aException%aerror message%a";

        $this->stream->expects($this->once())
            ->method('write')
            ->with($this->matches($expected));

        $this->object->critical($exception);
    }
}
