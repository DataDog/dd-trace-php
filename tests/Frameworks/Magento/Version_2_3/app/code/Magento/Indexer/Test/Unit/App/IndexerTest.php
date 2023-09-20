<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Indexer\Test\Unit\App;

class IndexerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Indexer\App\Indexer
     */
    protected $entryPoint;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Indexer\Model\Processor
     */
    protected $processor;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\Filesystem
     */
    protected $filesystem;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\App\Console\Response
     */
    protected $_response;

    protected function setUp(): void
    {
        $this->filesystem = $this->createPartialMock(\Magento\Framework\Filesystem::class, ['getDirectoryWrite']);
        $this->processor = $this->createMock(\Magento\Indexer\Model\Processor::class);
        $this->_response = $this->createPartialMock(
            \Magento\Framework\App\Console\Response::class,
            ['setCode', 'getCode']
        );

        $this->entryPoint = new \Magento\Indexer\App\Indexer(
            'reportDir',
            $this->filesystem,
            $this->processor,
            $this->_response
        );
    }

    /**
     * @param bool $isExist
     * @param array $callCount
     * @dataProvider executeProvider
     */
    public function testExecute($isExist, $callCount)
    {
        $this->_response->expects($this->once())->method('setCode')->with(0);
        $this->_response->expects($this->once())->method('getCode')->willReturn(0);
        $dir = $this->createMock(\Magento\Framework\Filesystem\Directory\Write::class);
        $dir->expects($this->any())->method('getRelativePath')->willReturnArgument(0);
        $dir->expects($this->once())->method('isExist')->willReturn($isExist);
        $dir->expects($this->exactly($callCount))->method('delete')->willReturn(true);
        $this->filesystem->expects($this->once())->method('getDirectoryWrite')->willReturn($dir);
        $this->processor->expects($this->once())->method('reindexAll');
        $this->assertEquals(0, $this->entryPoint->launch()->getCode());
    }

    /**
     * @return array
     */
    public function executeProvider()
    {
        return [
            'set1' => ['isExist' => true, 'expectsValue' => 1],
            'set1' => ['delete' => false, 'expectsValue' => 0]
        ];
    }

    public function testCatchException()
    {
        $bootstrap = $this->createMock(\Magento\Framework\App\Bootstrap::class);
        $this->assertFalse($this->entryPoint->catchException($bootstrap, new \Exception()));
    }
}
