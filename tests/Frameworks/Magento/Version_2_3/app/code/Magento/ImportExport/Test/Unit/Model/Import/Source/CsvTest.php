<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\ImportExport\Test\Unit\Model\Import\Source;

class CsvTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Filesystem|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_filesystem;

    /**
     * @var \Magento\Framework\Filesystem\Directory\Write|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_directoryMock;

    /**
     * Set up properties for all tests
     */
    protected function setUp(): void
    {
        $this->_filesystem = $this->createMock(\Magento\Framework\Filesystem::class);
        $this->_directoryMock = $this->createMock(\Magento\Framework\Filesystem\Directory\Write::class);
    }

    /**
     */
    public function testConstructException()
    {
        $this->expectException(\LogicException::class);

        $this->_directoryMock->expects($this->any())
            ->method('openFile')
            ->willThrowException(new \Magento\Framework\Exception\FileSystemException(__('Error message')));
        new \Magento\ImportExport\Model\Import\Source\Csv(__DIR__ . '/invalid_file', $this->_directoryMock);
    }

    public function testConstructStream()
    {
        $this->markTestSkipped('MAGETWO-17084: Replace PHP native calls');
        $stream = 'data://text/plain;base64,' . base64_encode("column1,column2\nvalue1,value2\n");
        $this->_directoryMock->expects(
            $this->any()
        )->method(
            'openFile'
        )->willReturn(
            
                new \Magento\Framework\Filesystem\File\Read($stream, new \Magento\Framework\Filesystem\Driver\Http())
            
        );
        $this->_filesystem->expects(
            $this->any()
        )->method(
            'getDirectoryWrite'
        )->willReturn(
            $this->_directoryMock
        );

        $model = new \Magento\ImportExport\Model\Import\Source\Csv($stream, $this->_filesystem);
        foreach ($model as $value) {
            $this->assertSame(['column1' => 'value1', 'column2' => 'value2'], $value);
        }
    }

    /**
     * @param string $delimiter
     * @param string $enclosure
     * @param array $expectedColumns
     * @dataProvider optionalArgsDataProvider
     */
    public function testOptionalArgs($delimiter, $enclosure, $expectedColumns)
    {
        $this->_directoryMock->expects(
            $this->any()
        )->method(
            'openFile'
        )->willReturn(
            
                new \Magento\Framework\Filesystem\File\Read(
                    __DIR__ . '/_files/test.csv',
                    new \Magento\Framework\Filesystem\Driver\File()
                )
            
        );
        $model = new \Magento\ImportExport\Model\Import\Source\Csv(
            __DIR__ . '/_files/test.csv',
            $this->_directoryMock,
            $delimiter,
            $enclosure
        );
        $this->assertSame($expectedColumns, $model->getColNames());
    }

    /**
     * @return array
     */
    public function optionalArgsDataProvider()
    {
        return [
            [',', '"', ['column1', 'column2']],
            [',', "'", ['column1', '"column2"']],
            ['.', '"', ['column1,"column2"']]
        ];
    }

    /**
     */
    public function testRewind()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('wrongColumnsNumber');

        $this->_directoryMock->expects(
            $this->any()
        )->method(
            'openFile'
        )->willReturn(
            
                new \Magento\Framework\Filesystem\File\Read(
                    __DIR__ . '/_files/test.csv',
                    new \Magento\Framework\Filesystem\Driver\File()
                )
            
        );
        $model = new \Magento\ImportExport\Model\Import\Source\Csv(
            __DIR__ . '/_files/test.csv',
            $this->_directoryMock
        );
        $this->assertSame(-1, $model->key());
        $model->next();
        $this->assertSame(0, $model->key());
        $model->next();
        $this->assertSame(1, $model->key());
        $model->rewind();
        $this->assertSame(0, $model->key());
        $model->next();
        $model->next();
        $this->assertSame(2, $model->key());
        $model->current();
    }
}
