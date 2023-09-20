<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\MediaStorage\Test\Unit\Model\ResourceModel\File\Storage;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * Class FileTest
 */
class FileTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Filesystem\Io\File
     */
    private $fileIoMock;

    /**
     * @var \Magento\MediaStorage\Model\ResourceModel\File\Storage\File
     */
    protected $storageFile;

    /**
     * @var \Magento\MediaStorage\Helper\File\Media|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $loggerMock;

    /**
     * @var \Magento\Framework\Filesystem|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $filesystemMock;

    /**
     * @var \Magento\Framework\Filesystem\Directory\Read|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $directoryReadMock;

    /**
     * Set up
     */
    protected function setUp(): void
    {
        $this->loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->filesystemMock = $this->createPartialMock(\Magento\Framework\Filesystem::class, ['getDirectoryRead']);
        $this->directoryReadMock = $this->createPartialMock(
            \Magento\Framework\Filesystem\Directory\Read::class,
            ['isDirectory', 'readRecursively']
        );

        $this->fileIoMock = $this->createPartialMock(\Magento\Framework\Filesystem\Io\File::class, ['getPathInfo']);

        $objectManager = new ObjectManager($this);

        $this->storageFile = $objectManager->getObject(
            \Magento\MediaStorage\Model\ResourceModel\File\Storage\File::class,
            [
                'filesystem' => $this->filesystemMock,
                'log' => $this->loggerMock,
                'fileIo' => $this->fileIoMock
            ]
        );
    }

    protected function tearDown(): void
    {
        unset($this->storageFile);
    }

    /**
     * test get storage data
     */
    public function testGetStorageData()
    {
        $this->filesystemMock->expects(
            $this->once()
        )->method(
            'getDirectoryRead'
        )->with(
            $this->equalTo(DirectoryList::MEDIA)
        )->willReturn(
            $this->directoryReadMock
        );

        $this->directoryReadMock->expects(
            $this->any()
        )->method(
            'isDirectory'
        )->willReturnMap(
            
                [
                    ['/', true],
                    ['folder_one', true],
                    ['file_three.txt', false],
                    ['folder_one/.svn', false],
                    ['folder_one/file_one.txt', false],
                    ['folder_one/folder_two', true],
                    ['folder_one/folder_two/.htaccess', false],
                    ['folder_one/folder_two/file_two.txt', false],
                ]
            
        );

        $paths = [
            'folder_one',
            'file_three.txt',
            'folder_one/.svn',
            'folder_one/file_one.txt',
            'folder_one/folder_two',
            'folder_one/folder_two/.htaccess',
            'folder_one/folder_two/file_two.txt',
        ];

        $pathInfos = array_map(
            function ($path) {
                return [$path, pathinfo($path)];
            },
            $paths
        );

        $this->fileIoMock->expects(
            $this->any()
        )->method(
            'getPathInfo'
        )->willReturnMap($pathInfos);

        sort($paths);
        $this->directoryReadMock->expects(
            $this->once()
        )->method(
            'readRecursively'
        )->with(
            $this->equalTo('/')
        )->willReturn(
            $paths
        );

        $expected = [
            'files' => ['file_three.txt', 'folder_one/file_one.txt', 'folder_one/folder_two/file_two.txt'],
            'directories' => [
                ['name' => 'folder_one', 'path' => '/'],
                ['name' => 'folder_two', 'path' => 'folder_one'],
            ],
        ];
        $actual = $this->storageFile->getStorageData();

        $this->assertEquals($expected, $actual);
    }
}
