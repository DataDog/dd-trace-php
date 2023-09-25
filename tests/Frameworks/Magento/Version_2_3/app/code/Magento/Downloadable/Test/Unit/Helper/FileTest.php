<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Downloadable\Test\Unit\Helper;

class FileTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Downloadable\Helper\File
     */
    private $file;

    /**
     * Core file storage database
     *
     * @var \Magento\MediaStorage\Helper\File\Storage\Database|\PHPUnit\Framework\MockObject\MockObject
     */
    private $coreFileStorageDatabase;

    /**
     * Filesystem object.
     *
     * @var \Magento\Framework\Filesystem|\PHPUnit\Framework\MockObject\MockObject
     */
    private $filesystem;

    /**
     * Media Directory object (writable).
     *
     * @var \Magento\Framework\Filesystem\Directory\WriteInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $mediaDirectory;

    /**
     * @var \Magento\Framework\App\Helper\Context|\PHPUnit\Framework\MockObject\MockObject
     */
    private $appContext;

    protected function setUp(): void
    {
        $this->mediaDirectory = $this->getMockBuilder(\Magento\Framework\Filesystem\Directory\WriteInterface::class)
            ->getMockForAbstractClass();

        $this->filesystem = $this->getMockBuilder(\Magento\Framework\Filesystem::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->filesystem->expects($this->any())
            ->method('getDirectoryWrite')
            ->with(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA)
            ->willReturn($this->mediaDirectory);

        $this->coreFileStorageDatabase =
            $this->getMockBuilder(\Magento\MediaStorage\Helper\File\Storage\Database::class)
                ->setMethods(['create'])
                ->disableOriginalConstructor()
                ->getMock();
        $this->appContext = $this->getMockBuilder(\Magento\Framework\App\Helper\Context::class)
            ->disableOriginalConstructor()
            ->setMethods(
                [
                    'getModuleManager',
                    'getLogger',
                    'getRequest',
                    'getUrlBuilder',
                    'getHttpHeader',
                    'getEventManager',
                    'getRemoteAddress',
                    'getCacheConfig',
                    'getUrlEncoder',
                    'getUrlDecoder',
                    'getScopeConfig'
                ]
            )
            ->getMock();
        $this->file = new \Magento\Downloadable\Helper\File(
            $this->appContext,
            $this->coreFileStorageDatabase,
            $this->filesystem
        );
    }

    public function testUploadFromTmp()
    {
        $uploaderMock = $this->getMockBuilder(\Magento\MediaStorage\Model\File\Uploader::class)
            ->disableOriginalConstructor()
            ->getMock();
        $uploaderMock->expects($this->once())->method('setAllowRenameFiles');
        $uploaderMock->expects($this->once())->method('setFilesDispersion');
        $this->mediaDirectory->expects($this->once())->method('getAbsolutePath')->willReturn('absPath');
        $uploaderMock->expects($this->once())->method('save')->with('absPath')
            ->willReturn(['file' => 'file.jpg', 'path' => 'absPath']);

        $result = $this->file->uploadFromTmp('tmpPath', $uploaderMock);

        $this->assertArrayNotHasKey('path', $result);
    }
}
