<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Test\Unit\Model;

use Magento\Customer\Api\AddressMetadataInterface;
use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Model\FileProcessor;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\Filesystem\Directory\WriteFactory;

/**
 * Test for \Magento\Customer\Model\FileProcessor class.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class FileProcessorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Filesystem|\PHPUnit\Framework\MockObject\MockObject
     */
    private $filesystem;

    /**
     * @var \Magento\MediaStorage\Model\File\UploaderFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $uploaderFactory;

    /**
     * @var \Magento\Framework\UrlInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $urlBuilder;

    /**
     * @var \Magento\Framework\Url\EncoderInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $urlEncoder;

    /**
     * @var \Magento\Framework\Filesystem\Directory\WriteInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $mediaDirectory;

    /**
     * @var \Magento\Framework\File\Mime|\PHPUnit\Framework\MockObject\MockObject
     */
    private $mime;

    /**
     * @var DirectoryList|\PHPUnit_Framework_MockObject_MockObject
     */
    private $directoryListMock;

    /**
     * @var WriteFactory|\PHPUnit_Framework_MockObject_MockObject
     */
    private $writeFactoryMock;

    protected function setUp(): void
    {
        $this->mediaDirectory = $this->getMockBuilder(\Magento\Framework\Filesystem\Directory\WriteInterface::class)
            ->getMockForAbstractClass();

        $this->filesystem = $this->getMockBuilder(\Magento\Framework\Filesystem::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->filesystem->expects($this->any())
            ->method('getDirectoryWrite')
            ->with(DirectoryList::MEDIA)
            ->willReturn($this->mediaDirectory);

        $this->uploaderFactory = $this->getMockBuilder(\Magento\MediaStorage\Model\File\UploaderFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->urlBuilder = $this->getMockBuilder(\Magento\Framework\UrlInterface::class)
            ->getMockForAbstractClass();

        $this->urlEncoder = $this->getMockBuilder(\Magento\Framework\Url\EncoderInterface::class)
            ->getMockForAbstractClass();

        $this->mime = $this->getMockBuilder(\Magento\Framework\File\Mime::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->directoryListMock = $this->createMock(DirectoryList::class);
        $this->writeFactoryMock = $this->createMock(WriteFactory::class);
        $this->writeFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->mediaDirectory);
    }

    /**
     * @param $entityTypeCode
     * @param array $allowedExtensions
     * @return FileProcessor
     */
    private function getModel($entityTypeCode, array $allowedExtensions = [])
    {
        $objectManager = new ObjectManager($this);

        $model = $objectManager->getObject(
            FileProcessor::class,
            [
                'filesystem' => $this->filesystem,
                'uploaderFactory' => $this->uploaderFactory,
                'urlBuilder' => $this->urlBuilder,
                'urlEncoder' => $this->urlEncoder,
                'entityTypeCode' => $entityTypeCode,
                'mime' => $this->mime,
                'allowedExtensions' => $allowedExtensions,
                'writeFactory' => $this->writeFactoryMock,
                'directoryList' => $this->directoryListMock,
            ]
        );

        return $model;
    }

    public function testGetStat()
    {
        $fileName = '/filename.ext1';

        $this->mediaDirectory->expects($this->once())
            ->method('stat')
            ->with($fileName)
            ->willReturn(['size' => 1]);

        $model = $this->getModel(CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER);
        $result = $model->getStat($fileName);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('size', $result);
        $this->assertEquals(1, $result['size']);
    }

    public function testIsExist()
    {
        $fileName = '/filename.ext1';

        $this->mediaDirectory->expects($this->once())
            ->method('isExist')
            ->with($fileName)
            ->willReturn(true);

        $model = $this->getModel(CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER);
        $this->assertTrue($model->isExist($fileName));
    }

    public function testGetViewUrlCustomer()
    {
        $filePath = 'filename.ext1';
        $encodedFilePath = 'encodedfilenameext1';

        $fileUrl = 'fileUrl';

        $this->urlEncoder->expects($this->once())
            ->method('encode')
            ->with($filePath)
            ->willReturn($encodedFilePath);

        $this->urlBuilder->expects($this->once())
            ->method('getUrl')
            ->with('customer/index/viewfile', ['image' => $encodedFilePath])
            ->willReturn($fileUrl);

        $model = $this->getModel(CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER);
        $this->assertEquals($fileUrl, $model->getViewUrl($filePath, 'image'));
    }

    public function testGetViewUrlCustomerAddress()
    {
        $filePath = 'filename.ext1';

        $baseUrl = 'baseUrl';
        $relativeUrl = 'relativeUrl';

        $this->urlBuilder->expects($this->once())
            ->method('getBaseUrl')
            ->with(['_type' => \Magento\Framework\UrlInterface::URL_TYPE_MEDIA])
            ->willReturn($baseUrl);

        $this->mediaDirectory->expects($this->once())
            ->method('getRelativePath')
            ->with(AddressMetadataInterface::ENTITY_TYPE_ADDRESS . '/' . $filePath)
            ->willReturn($relativeUrl);

        $model = $this->getModel(AddressMetadataInterface::ENTITY_TYPE_ADDRESS);
        $this->assertEquals($baseUrl . $relativeUrl, $model->getViewUrl($filePath, 'image'));
    }

    public function testRemoveUploadedFile()
    {
        $fileName = '/filename.ext1';

        $this->mediaDirectory->expects($this->once())
            ->method('delete')
            ->with($fileName)
            ->willReturn(true);

        $model = $this->getModel(CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER);
        $this->assertTrue($model->removeUploadedFile($fileName));
    }

    public function testSaveTemporaryFile()
    {
        $attributeCode = 'img1';

        $allowedExtensions = [
            'ext1',
            'ext2',
        ];

        $absolutePath = '/absolute/filepath';

        $expectedResult = [
            'file' => 'filename.ext1',
        ];
        $resultWithPath = [
            'file' => 'filename.ext1',
            'path' => 'filepath'
        ];

        $uploaderMock = $this->getMockBuilder(\Magento\MediaStorage\Model\File\Uploader::class)
            ->disableOriginalConstructor()
            ->getMock();
        $uploaderMock->expects($this->once())
            ->method('setFilesDispersion')
            ->with(false)
            ->willReturnSelf();
        $uploaderMock->expects($this->once())
            ->method('setFilenamesCaseSensitivity')
            ->with(false)
            ->willReturnSelf();
        $uploaderMock->expects($this->once())
            ->method('setAllowRenameFiles')
            ->with(true)
            ->willReturnSelf();
        $uploaderMock->expects($this->once())
            ->method('setAllowedExtensions')
            ->with($allowedExtensions)
            ->willReturnSelf();
        $uploaderMock->expects($this->once())
            ->method('save')
            ->with($absolutePath)
            ->willReturn($resultWithPath);

        $this->uploaderFactory->expects($this->once())
            ->method('create')
            ->with(['fileId' => 'customer[' . $attributeCode . ']'])
            ->willReturn($uploaderMock);

        $this->mediaDirectory->expects($this->once())
            ->method('getAbsolutePath')
            ->with('/' . FileProcessor::TMP_DIR)
            ->willReturn($absolutePath);

        $model = $this->getModel(CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER, $allowedExtensions);
        $result = $model->saveTemporaryFile('customer[' . $attributeCode . ']');

        $this->assertEquals($expectedResult, $result);
    }

    /**
     */
    public function testSaveTemporaryFileWithError()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('File can not be saved to the destination folder.');

        $attributeCode = 'img1';

        $allowedExtensions = [
            'ext1',
            'ext2',
        ];

        $absolutePath = '/absolute/filepath';

        $uploaderMock = $this->getMockBuilder(\Magento\MediaStorage\Model\File\Uploader::class)
            ->disableOriginalConstructor()
            ->getMock();
        $uploaderMock->expects($this->once())
            ->method('setFilesDispersion')
            ->with(false)
            ->willReturnSelf();
        $uploaderMock->expects($this->once())
            ->method('setFilenamesCaseSensitivity')
            ->with(false)
            ->willReturnSelf();
        $uploaderMock->expects($this->once())
            ->method('setAllowRenameFiles')
            ->with(true)
            ->willReturnSelf();
        $uploaderMock->expects($this->once())
            ->method('setAllowedExtensions')
            ->with($allowedExtensions)
            ->willReturnSelf();
        $uploaderMock->expects($this->once())
            ->method('save')
            ->with($absolutePath)
            ->willReturn(false);

        $this->uploaderFactory->expects($this->once())
            ->method('create')
            ->with(['fileId' => 'customer[' . $attributeCode . ']'])
            ->willReturn($uploaderMock);

        $this->mediaDirectory->expects($this->once())
            ->method('getAbsolutePath')
            ->with('/' . FileProcessor::TMP_DIR)
            ->willReturn($absolutePath);

        $model = $this->getModel(CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER, $allowedExtensions);
        $model->saveTemporaryFile('customer[' . $attributeCode . ']');
    }

    /**
     */
    public function testMoveTemporaryFileUnableToCreateDirectory()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('Unable to create directory /f/i');

        $filePath = '/filename.ext1';

        $destinationPath = '/f/i';

        $this->mediaDirectory->expects($this->once())
            ->method('create')
            ->with($destinationPath)
            ->willReturn(false);

        $model = $this->getModel(CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER);
        $model->moveTemporaryFile($filePath);
    }

    /**
     */
    public function testMoveTemporaryFileDestinationFolderDoesNotExists()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('Destination folder is not writable or does not exists');

        $filePath = '/filename.ext1';

        $destinationPath = '/f/i';

        $this->mediaDirectory->expects($this->once())
            ->method('create')
            ->with($destinationPath)
            ->willReturn(true);
        $this->mediaDirectory->expects($this->once())
            ->method('isWritable')
            ->with($destinationPath)
            ->willReturn(false);

        $model = $this->getModel(CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER);
        $model->moveTemporaryFile($filePath);
    }

    public function testMoveTemporaryFile()
    {
        $filePath = '/filename.ext1';

        $destinationPath = '/f/i';

        $this->mediaDirectory->expects($this->once())
            ->method('create')
            ->with($destinationPath)
            ->willReturn(true);
        $this->mediaDirectory->expects($this->once())
            ->method('isWritable')
            ->with($destinationPath)
            ->willReturn(true);
        $this->mediaDirectory->expects($this->once())
            ->method('getAbsolutePath')
            ->with($destinationPath)
            ->willReturn('/' . $destinationPath);

        $path = '/' . FileProcessor::TMP_DIR . $filePath;
        $newPath = $destinationPath . $filePath;

        $this->mediaDirectory->expects($this->once())
            ->method('renameFile')
            ->with($path, $newPath)
            ->willReturn(true);

        $model = $this->getModel(CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER);
        $this->assertEquals('/f/i' . $filePath, $model->moveTemporaryFile($filePath));
    }

    /**
     */
    public function testMoveTemporaryFileWithException()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('Something went wrong while saving the file');

        $filePath = '/filename.ext1';

        $destinationPath = '/f/i';

        $this->mediaDirectory->expects($this->once())
            ->method('create')
            ->with($destinationPath)
            ->willReturn(true);
        $this->mediaDirectory->expects($this->once())
            ->method('isWritable')
            ->with($destinationPath)
            ->willReturn(true);
        $this->mediaDirectory->expects($this->once())
            ->method('getAbsolutePath')
            ->with($destinationPath)
            ->willReturn('/' . $destinationPath);

        $path = '/' . FileProcessor::TMP_DIR . $filePath;
        $newPath = $destinationPath . $filePath;

        $this->mediaDirectory->expects($this->once())
            ->method('renameFile')
            ->with($path, $newPath)
            ->willThrowException(new \Exception('Exception.'));

        $model = $this->getModel(CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER);
        $model->moveTemporaryFile($filePath);
    }

    public function testGetMimeType()
    {
        $fileName = '/filename.ext1';
        $absoluteFilePath = '/absolute_path/customer/filename.ext1';

        $expected = 'ext1';

        $this->mediaDirectory->expects($this->once())
            ->method('getAbsolutePath')
            ->with($fileName)
            ->willReturn($absoluteFilePath);

        $this->mime->expects($this->once())
            ->method('getMimeType')
            ->with($absoluteFilePath)
            ->willReturn($expected);

        $model = $this->getModel(CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER);

        $this->assertEquals($expected, $model->getMimeType($fileName));
    }
}
