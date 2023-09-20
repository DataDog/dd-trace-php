<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Downloadable\Test\Unit\Helper;

use Magento\Downloadable\Helper\Download as DownloadHelper;
use Magento\Downloadable\Helper\File as DownloadableFile;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface as DirReadInterface;
use Magento\Framework\Filesystem\File\ReadInterface as FileReadInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DownloadTest extends \PHPUnit\Framework\TestCase
{
    /** @var DownloadHelper */
    protected $_helper;

    /** @var Filesystem|\PHPUnit\Framework\MockObject\MockObject */
    protected $_filesystemMock;

    /** @var FileReadInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $_handleMock;

    /** @var DirReadInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $_workingDirectoryMock;

    /** @var DownloadableFile|\PHPUnit\Framework\MockObject\MockObject */
    protected $_downloadableFileMock;

    /** @var  \Magento\Framework\Session\SessionManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $sessionManager;

    /** @var \Magento\Framework\Filesystem\File\ReadFactory|\PHPUnit\Framework\MockObject\MockObject */
    protected $fileReadFactory;

    /** @var bool Result of function_exists() */
    public static $functionExists;

    /** @var string Result of mime_content_type() */
    public static $mimeContentType;

    const FILE_SIZE = 4096;

    const FILE_PATH = '/some/path';

    const MIME_TYPE = 'image/png';

    const URL = 'http://example.com';

    protected function setUp(): void
    {
        require_once __DIR__ . '/../_files/download_mock.php';

        self::$functionExists = true;
        self::$mimeContentType = self::MIME_TYPE;

        $this->_filesystemMock = $this->createMock(\Magento\Framework\Filesystem::class);
        $this->_handleMock = $this->createMock(\Magento\Framework\Filesystem\File\ReadInterface::class);
        $this->_workingDirectoryMock = $this->createMock(\Magento\Framework\Filesystem\Directory\ReadInterface::class);
        $this->_downloadableFileMock = $this->createMock(\Magento\Downloadable\Helper\File::class);
        $this->sessionManager = $this->getMockForAbstractClass(
            \Magento\Framework\Session\SessionManagerInterface::class
        );
        $this->fileReadFactory = $this->createMock(\Magento\Framework\Filesystem\File\ReadFactory::class);

        $this->_helper = (new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this))->getObject(
            \Magento\Downloadable\Helper\Download::class,
            [
                'downloadableFile' => $this->_downloadableFileMock,
                'filesystem'       => $this->_filesystemMock,
                'session'          => $this->sessionManager,
                'fileReadFactory'  => $this->fileReadFactory,
            ]
        );
    }

    /**
     */
    public function testSetResourceInvalidPath()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->_helper->setResource('/some/path/../file', DownloadHelper::LINK_TYPE_FILE);
    }

    /**
     */
    public function testGetFileSizeNoResource()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('Please set resource file and link type.');

        $this->_helper->getFileSize();
    }

    /**
     */
    public function testGetFileSizeInvalidLinkType()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('Invalid download link type.');

        $this->_helper->setResource(self::FILE_PATH, 'The link type is invalid. Verify and try again.');
        $this->_helper->getFileSize();
    }

    public function testGetFileSizeUrl()
    {
        $this->_setupUrlMocks();
        $this->assertEquals(self::FILE_SIZE, $this->_helper->getFileSize());
    }

    public function testGetFileSize()
    {
        $this->_setupFileMocks();
        $this->assertEquals(self::FILE_SIZE, $this->_helper->getFileSize());
    }

    /**
     */
    public function testGetFileSizeNoFile()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('Invalid download link type.');

        $this->_setupFileMocks(false);
        $this->_helper->getFileSize();
    }

    public function testGetContentType()
    {
        $this->_setupFileMocks();
        $this->_downloadableFileMock->expects($this->never())->method('getFileType');
        $this->assertEquals(self::MIME_TYPE, $this->_helper->getContentType());
    }

    /**
     * @dataProvider dataProviderForTestGetContentTypeThroughHelper
     */
    public function testGetContentTypeThroughHelper($functionExistsResult, $mimeContentTypeResult)
    {
        $this->_setupFileMocks();
        self::$functionExists = $functionExistsResult;
        self::$mimeContentType = $mimeContentTypeResult;

        $this->_downloadableFileMock->expects(
            $this->once()
        )->method(
            'getFileType'
        )->willReturn(
            self::MIME_TYPE
        );

        $this->assertEquals(self::MIME_TYPE, $this->_helper->getContentType());
    }

    /**
     * @return array
     */
    public function dataProviderForTestGetContentTypeThroughHelper()
    {
        return [[false, ''], [true, false]];
    }

    public function testGetContentTypeUrl()
    {
        $this->_setupUrlMocks();
        $this->assertEquals(self::MIME_TYPE, $this->_helper->getContentType());
    }

    public function testGetFilename()
    {
        $baseName = 'base_name.file';
        $path = TESTS_TEMP_DIR . '/' . $baseName;
        $this->_setupFileMocks(true, self::FILE_SIZE, $path);
        $this->assertEquals($baseName, $this->_helper->getFilename());
    }

    public function testGetFileNameUrl()
    {
        $this->_setupUrlMocks();
        $this->assertEquals('example.com', $this->_helper->getFilename());
    }

    public function testGetFileNameUrlWithContentDisposition()
    {
        $fileName = 'some_other.file';
        $this->_setupUrlMocks(self::FILE_SIZE, self::URL, ['disposition' => "inline; filename={$fileName}"]);
        $this->assertEquals($fileName, $this->_helper->getFilename());
    }

    /**
     * @param bool $doesExist
     * @param int $size
     * @param string $path
     */
    protected function _setupFileMocks($doesExist = true, $size = self::FILE_SIZE, $path = self::FILE_PATH)
    {
        $this->_handleMock->expects($this->any())->method('stat')->willReturn(['size' => $size]);
        $this->_downloadableFileMock->expects($this->any())->method('ensureFileInFilesystem')->with($path)
            ->willReturn($doesExist);
        $this->_workingDirectoryMock->expects($doesExist ? $this->once() : $this->never())->method('openFile')
            ->willReturn($this->_handleMock);
        $this->_filesystemMock->expects($this->any())->method('getDirectoryRead')->with(DirectoryList::MEDIA)
            ->willReturn($this->_workingDirectoryMock);
        $this->_helper->setResource($path, DownloadHelper::LINK_TYPE_FILE);
    }

    /**
     * @param int $size
     * @param string $url
     * @param array $additionalStatData
     */
    protected function _setupUrlMocks($size = self::FILE_SIZE, $url = self::URL, $additionalStatData = [])
    {
        $this->_handleMock->expects(
            $this->any()
        )->method(
            'stat'
        )->willReturn(
            array_merge(['size' => $size, 'type' => self::MIME_TYPE], $additionalStatData)
        );

        $this->fileReadFactory->expects(
            $this->once()
        )->method(
            'create'
        )->willReturn(
            $this->_handleMock
        );

        $this->_helper->setResource($url, DownloadHelper::LINK_TYPE_URL);
    }

    public function testOutput()
    {
        $this->sessionManager
            ->expects($this->once())->method('writeClose');
        $this->_setupUrlMocks(self::FILE_SIZE, self::URL, ['disposition' => "inline; filename=test.txt"]);
        $this->_helper->output();
    }
}
