<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Customer\Test\Unit\Model\Metadata\Form;

use Magento\Customer\Api\AddressMetadataInterface;
use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Api\Data\ValidationRuleInterface;
use Magento\Customer\Model\FileProcessor;
use Magento\Customer\Model\FileProcessorFactory;
use Magento\Customer\Model\Metadata\Form\Image;
use Magento\Framework\Api\Data\ImageContentInterface;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\File\UploaderFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\Framework\Filesystem\Driver\File as Driver;
use Magento\Framework\Filesystem\Io\File;
use Magento\Framework\Url\EncoderInterface;
use Magento\MediaStorage\Model\File\Validator\NotProtectedExtension;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests Metadata/Form/Image class
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ImageTest extends AbstractFormTestCase
{
    /**
     * @var MockObject|EncoderInterface
     */
    private $urlEncode;

    /**
     * @var MockObject|NotProtectedExtension
     */
    private $fileValidatorMock;

    /**
     * @var MockObject|Filesystem
     */
    private $fileSystemMock;

    /**
     * @var MockObject|Http
     */
    private $requestMock;

    /**
     * @var MockObject|UploaderFactory
     */
    private $uploaderFactoryMock;

    /**
     * @var FileProcessor|MockObject
     */
    private $fileProcessorMock;

    /**
     * @var ImageContentInterfaceFactory|MockObject
     */
    private $imageContentFactory;

    /**
     * @var FileProcessorFactory|MockObject
     */
    private $fileProcessorFactoryMock;

    /**
     * @var File|\PHPUnit\Framework\MockObject\MockObject
     */
    private $ioFileSystemMock;

    /**
     * @var ReadInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $readDirectoryMock;

    /**
     * @var Write|\PHPUnit\Framework\MockObject\MockObject
     */
    private $mediaWriteDirectoryMock;

    /**
     * @var Driver|\PHPUnit\Framework\MockObject\MockObject
     */
    private $driverMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->urlEncode = $this->getMockBuilder(EncoderInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->fileValidatorMock = $this->getMockBuilder(NotProtectedExtension::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->fileSystemMock = $this->getMockBuilder(Filesystem::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->requestMock = $this->getMockBuilder(Http::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->uploaderFactoryMock = $this->getMockBuilder(UploaderFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->fileProcessorMock = $this->getMockBuilder(FileProcessor::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->imageContentFactory = $this->getMockBuilder(ImageContentInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->fileProcessorFactoryMock = $this->getMockBuilder(FileProcessorFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->fileProcessorFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($this->fileProcessorMock);
        $this->ioFileSystemMock = $this->getMockBuilder(File::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->readDirectoryMock = $this->getMockBuilder(ReadInterface::class)
            ->getMockForAbstractClass();
        $this->fileSystemMock->expects($this->once())
            ->method('getDirectoryReadByPath')
            ->willReturn($this->readDirectoryMock);

        $this->mediaWriteDirectoryMock = $this->getMockBuilder(Write::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->fileSystemMock->expects($this->once())
            ->method('getDirectoryWrite')
            ->willReturn($this->mediaWriteDirectoryMock);

        $this->driverMock = $this->getMockBuilder(Driver::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->mediaWriteDirectoryMock->expects($this->any())
            ->method('getDriver')
            ->willReturn($this->driverMock);
    }

    /**
     * Initializes an image instance
     *
     * @param array $data
     * @return Image
     * @throws FileSystemException
     */
    private function initialize(array $data): Image
    {
        return new Image(
            $this->localeMock,
            $this->loggerMock,
            $this->attributeMetadataMock,
            $this->localeResolverMock,
            $data['value'],
            $data['entityTypeCode'],
            $data['isAjax'],
            $this->urlEncode,
            $this->fileValidatorMock,
            $this->fileSystemMock,
            $this->uploaderFactoryMock,
            $this->fileProcessorFactoryMock,
            $this->imageContentFactory,
            $this->ioFileSystemMock
        );
    }

    /**
     * Test for validateValue method for not valid file
     * @throws LocalizedException
     */
    public function testValidateIsNotValidFile()
    {
        $value = [
            'tmp_name' => 'tmp_file.txt',
            'name' => 'realFileName',
        ];

        $this->attributeMetadataMock->expects($this->once())
            ->method('getStoreLabel')
            ->willReturn('File Input Field Label');

        $this->fileProcessorMock->expects($this->once())
            ->method('isExist')
            ->with(FileProcessor::TMP_DIR . '/' . $value['tmp_name'])
            ->willReturn(true);

        $model = $this->initialize([
            'value' => $value,
            'isAjax' => false,
            'entityTypeCode' => CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
        ]);

        $this->assertEquals(['"realFileName" is not a valid file.'], $model->validateValue($value));
    }

    /**
     * Test for validateValue method
     * @throws LocalizedException
     */
    public function testValidate()
    {
        $value = [
            'tmp_name' => __DIR__ . '/_files/logo.gif',
            'name' => 'logo.gif',
        ];

        $this->ioFileSystemMock->expects($this->any())
            ->method('getPathInfo')
            ->with($value['name'])
            ->willReturn([
                'filename' => 'logo',
                'extension' => 'gif'
            ]);

        $this->attributeMetadataMock->expects($this->once())
            ->method('getStoreLabel')
            ->willReturn('File Input Field Label');

        $this->fileProcessorMock->expects($this->once())
            ->method('isExist')
            ->with(FileProcessor::TMP_DIR . '/' . $value['name'])
            ->willReturn(true);

        $this->ioFileSystemMock->expects($this->once())
            ->method('getPathInfo')
            ->with($value['name'])
            ->willReturn(['extension' => 'gif']);

        $model = $this->initialize([
            'value' => $value,
            'isAjax' => false,
            'entityTypeCode' => CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
        ]);

        $this->assertTrue($model->validateValue($value));
    }

    /**
     * Test for validateValue method for max file size
     * @throws LocalizedException
     */
    public function testValidateMaxFileSize()
    {
        $value = [
            'tmp_name' => __DIR__ . '/_files/logo.gif',
            'name' => 'logo.gif',
            'size' => 2,
        ];

        $maxFileSize = 1;

        $validationRuleMock = $this->getMockBuilder(
            ValidationRuleInterface::class
        )->getMockForAbstractClass();
        $validationRuleMock->expects($this->any())
            ->method('getName')
            ->willReturn('max_file_size');
        $validationRuleMock->expects($this->any())
            ->method('getValue')
            ->willReturn($maxFileSize);

        $this->ioFileSystemMock->expects($this->any())
            ->method('getPathInfo')
            ->with($value['name'])
            ->willReturn([
                'filename' => 'logo',
                'extension' => 'gif'
            ]);

        $this->attributeMetadataMock->expects($this->once())
            ->method('getStoreLabel')
            ->willReturn('File Input Field Label');
        $this->attributeMetadataMock->expects($this->once())
            ->method('getValidationRules')
            ->willReturn([$validationRuleMock]);

        $this->fileProcessorMock->expects($this->once())
            ->method('isExist')
            ->with(FileProcessor::TMP_DIR . '/' . $value['name'])
            ->willReturn(true);

        $this->ioFileSystemMock->expects($this->once())
            ->method('getPathInfo')
            ->with($value['name'])
            ->willReturn(['extension' => 'gif']);

        $model = $this->initialize([
            'value' => $value,
            'isAjax' => false,
            'entityTypeCode' => CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
        ]);

        $this->assertEquals(['"logo.gif" exceeds the allowed file size.'], $model->validateValue($value));
    }

    /**
     * Test for validateValue method for max image width
     * @throws LocalizedException
     */
    public function testValidateMaxImageWidth()
    {
        $value = [
            'tmp_name' => __DIR__ . '/_files/logo.gif',
            'name' => 'logo.gif',
        ];

        $maxImageWidth = 1;

        $validationRuleMock = $this->getMockBuilder(
            ValidationRuleInterface::class
        )->getMockForAbstractClass();
        $validationRuleMock->expects($this->any())
            ->method('getName')
            ->willReturn('max_image_width');
        $validationRuleMock->expects($this->any())
            ->method('getValue')
            ->willReturn($maxImageWidth);

        $this->ioFileSystemMock->expects($this->any())
            ->method('getPathInfo')
            ->with($value['name'])
            ->willReturn([
                'filename' => 'logo',
                'extension' => 'gif'
            ]);

        $this->attributeMetadataMock->expects($this->once())
            ->method('getStoreLabel')
            ->willReturn('File Input Field Label');
        $this->attributeMetadataMock->expects($this->once())
            ->method('getValidationRules')
            ->willReturn([$validationRuleMock]);

        $this->fileProcessorMock->expects($this->once())
            ->method('isExist')
            ->with(FileProcessor::TMP_DIR . '/' . $value['name'])
            ->willReturn(true);

        $this->ioFileSystemMock->expects($this->once())
            ->method('getPathInfo')
            ->with($value['name'])
            ->willReturn(['extension' => 'gif']);

        $model = $this->initialize([
            'value' => $value,
            'isAjax' => false,
            'entityTypeCode' => CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
        ]);

        $this->assertEquals(['"logo.gif" width exceeds allowed value of 1 px.'], $model->validateValue($value));
    }

    /**
     * Test for validateValue method for max image height
     * @throws LocalizedException
     */
    public function testValidateMaxImageHeight()
    {
        $value = [
            'tmp_name' => __DIR__ . '/_files/logo.gif',
            'name' => 'logo.gif',
        ];

        $maxImageHeight = 1;

        $validationRuleMock = $this->getMockBuilder(
            ValidationRuleInterface::class
        )->getMockForAbstractClass();
        $validationRuleMock->expects($this->any())
            ->method('getName')
            ->willReturn('max_image_height');
        $validationRuleMock->expects($this->any())
            ->method('getValue')
            ->willReturn($maxImageHeight);

        $this->ioFileSystemMock->expects($this->any())
            ->method('getPathInfo')
            ->with($value['name'])
            ->willReturn([
                'filename' => 'logo',
                'extension' => 'gif'
            ]);

        $this->attributeMetadataMock->expects($this->once())
            ->method('getStoreLabel')
            ->willReturn('File Input Field Label');
        $this->attributeMetadataMock->expects($this->once())
            ->method('getValidationRules')
            ->willReturn([$validationRuleMock]);

        $this->fileProcessorMock->expects($this->once())
            ->method('isExist')
            ->with(FileProcessor::TMP_DIR . '/' . $value['name'])
            ->willReturn(true);

        $this->ioFileSystemMock->expects($this->once())
            ->method('getPathInfo')
            ->with($value['name'])
            ->willReturn(['extension' => 'gif']);

        $model = $this->initialize([
            'value' => $value,
            'isAjax' => false,
            'entityTypeCode' => CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
        ]);

        $this->assertEquals(['"logo.gif" height exceeds allowed value of 1 px.'], $model->validateValue($value));
    }

    /**
     * Test for compactValue method
     * @throws LocalizedException
     */
    public function testCompactValueNoChanges()
    {
        $originValue = 'filename.ext1';

        $value = [
            'file' => $originValue,
        ];

        $model = $this->initialize([
            'value' => $originValue,
            'isAjax' => false,
            'entityTypeCode' => CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
        ]);

        $this->assertEquals($originValue, $model->compactValue($value));
    }

    /**
     * Test for compactValue method for address image
     * @throws LocalizedException
     */
    public function testCompactValueUiComponentAddress()
    {
        $originValue = 'filename.ext1';

        $value = [
            'file' => 'filename.ext2',
        ];

        $this->driverMock->expects($this->once())
            ->method('getRealPathSafety')
            ->with($value['file'])
            ->willReturn($value['file']);
        $this->readDirectoryMock->expects($this->once())
            ->method('getAbsolutePath')
            ->willReturn($value['file']);
        $this->readDirectoryMock->expects($this->once())
            ->method('getRelativePath')
            ->willReturn($value['file']);
        $this->fileProcessorMock->expects($this->once())
            ->method('moveTemporaryFile')
            ->with($value['file'])
            ->willReturn($value['file']);
        $model = $this->initialize([
            'value' => $originValue,
            'isAjax' => false,
            'entityTypeCode' => AddressMetadataInterface::ENTITY_TYPE_ADDRESS,
        ]);

        $this->assertEquals($value['file'], $model->compactValue($value));
    }

    /**
     * Test for compactValue method for image
     * @throws LocalizedException
     */
    public function testCompactValueUiComponentCustomer()
    {
        $originValue = 'filename.ext1';

        $value = [
            'file' => 'filename.ext2',
            'name' => 'filename.ext2',
            'type' => 'image',
        ];

        $base64EncodedData = 'encoded_data';

        $this->readDirectoryMock->expects($this->once())
            ->method('isExist')
            ->with($value['file'])
            ->willReturn(true);
        $this->fileProcessorMock->expects($this->once())
            ->method('getBase64EncodedData')
            ->with(FileProcessor::TMP_DIR . '/' . $value['file'])
            ->willReturn($base64EncodedData);
        $this->fileProcessorMock->expects($this->once())
            ->method('removeUploadedFile')
            ->with(FileProcessor::TMP_DIR . '/' . $value['file'])
            ->willReturnSelf();

        $imageContentMock = $this->getMockBuilder(
            ImageContentInterface::class
        )->getMockForAbstractClass();
        $imageContentMock->expects($this->once())
            ->method('setName')
            ->with($value['name'])
            ->willReturnSelf();
        $imageContentMock->expects($this->once())
            ->method('setBase64EncodedData')
            ->with($base64EncodedData)
            ->willReturnSelf();
        $imageContentMock->expects($this->once())
            ->method('setType')
            ->with($value['type'])
            ->willReturnSelf();

        $this->imageContentFactory->expects($this->once())
            ->method('create')
            ->willReturn($imageContentMock);

        $model = $this->initialize([
            'value' => $originValue,
            'isAjax' => false,
            'entityTypeCode' => CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
        ]);

        $this->assertEquals($imageContentMock, $model->compactValue($value));
    }

    /**
     * Test for compactValue method for non-existing customer
     * @throws LocalizedException
     */
    public function testCompactValueUiComponentCustomerNotExists()
    {
        $originValue = 'filename.ext1';

        $value = [
            'file' => 'filename.ext2',
            'name' => 'filename.ext2',
            'type' => 'image',
        ];

        $this->readDirectoryMock->expects($this->once())
            ->method('isExist')
            ->with($value['file'])
            ->willReturn(false);

        $model = $this->initialize([
            'value' => $originValue,
            'isAjax' => false,
            'entityTypeCode' => CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
        ]);

        $this->assertEquals($originValue, $model->compactValue($value));
    }
}
