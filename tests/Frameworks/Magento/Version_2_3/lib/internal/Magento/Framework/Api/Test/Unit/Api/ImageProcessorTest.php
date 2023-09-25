<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Api\Test\Unit\Api;

/**
 * Unit test class for \Magento\Framework\Api\ImageProcessor
 */
class ImageProcessorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Api\ImageProcessor
     */
    protected $imageProcessor;

    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManager;

    /**
     * @var \Magento\Framework\Filesystem|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $fileSystemMock;

    /**
     * @var \Magento\Framework\Api\ImageContentValidatorInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $contentValidatorMock;

    /**
     * @var \Magento\Framework\Api\DataObjectHelper|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $dataObjectHelperMock;

    /**
     * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $loggerMock;

    /**
     * @var \Magento\Framework\Api\Uploader|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $uploaderMock;

    /**
     * @var \Magento\Framework\Filesystem\Directory\WriteInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $directoryWriteMock;

    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->directoryWriteMock = $this->getMockForAbstractClass(
            \Magento\Framework\Filesystem\Directory\WriteInterface::class
        );
        $this->fileSystemMock = $this->getMockBuilder(\Magento\Framework\Filesystem::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->fileSystemMock->expects($this->any())
            ->method('getDirectoryWrite')
            ->willReturn($this->directoryWriteMock);
        $this->contentValidatorMock = $this->getMockBuilder(
            \Magento\Framework\Api\ImageContentValidatorInterface::class
        )
            ->disableOriginalConstructor()
            ->getMock();
        $this->dataObjectHelperMock = $this->getMockBuilder(\Magento\Framework\Api\DataObjectHelper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->loggerMock = $this->getMockBuilder(\Psr\Log\LoggerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->uploaderMock = $this->getMockBuilder(\Magento\Framework\Api\Uploader::class)
            ->setMethods(
                [
                    'processFileAttributes',
                    'setFilesDispersion',
                    'setFilenamesCaseSensitivity',
                    'setAllowRenameFiles',
                    'save',
                    'getUploadedFileName'
                ]
            )
            ->disableOriginalConstructor()
            ->getMock();

        $this->imageProcessor = $this->objectManager->getObject(
            \Magento\Framework\Api\ImageProcessor::class,
            [
                'fileSystem' => $this->fileSystemMock,
                'contentValidator' => $this->contentValidatorMock,
                'dataObjectHelper' => $this->dataObjectHelperMock,
                'logger' => $this->loggerMock,
                'uploader' => $this->uploaderMock
            ]
        );
    }

    public function testSaveWithNoImageData()
    {
        $imageDataMock = $this->getMockBuilder(\Magento\Framework\Api\CustomAttributesDataInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $imageDataMock->expects($this->once())
            ->method('getCustomAttributes')
            ->willReturn([]);

        $this->dataObjectHelperMock->expects($this->once())
            ->method('getCustomAttributeValueByType')
            ->willReturn([]);

        $this->assertEquals($imageDataMock, $this->imageProcessor->save($imageDataMock, 'testEntityType'));
    }

    /**
     */
    public function testSaveInputException()
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);
        $this->expectExceptionMessage('The image content is invalid. Verify the content and try again.');

        $imageContent = $this->getMockBuilder(\Magento\Framework\Api\Data\ImageContentInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $imageDataObject = $this->getMockBuilder(\Magento\Framework\Api\AttributeValue::class)
            ->disableOriginalConstructor()
            ->getMock();
        $imageDataObject->expects($this->once())
            ->method('getValue')
            ->willReturn($imageContent);

        $imageDataMock = $this->getMockBuilder(\Magento\Framework\Api\CustomAttributesDataInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $imageDataMock->expects($this->once())
            ->method('getCustomAttributes')
            ->willReturn([]);

        $this->dataObjectHelperMock->expects($this->once())
            ->method('getCustomAttributeValueByType')
            ->willReturn([$imageDataObject]);

        $this->contentValidatorMock->expects($this->once())
            ->method('isValid')
            ->willReturn(false);

        $this->imageProcessor->save($imageDataMock, 'testEntityType');
    }

    public function testSaveWithNoPreviousData()
    {
        $imageContent = $this->getMockBuilder(\Magento\Framework\Api\Data\ImageContentInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $imageContent->expects($this->any())
            ->method('getBase64EncodedData')
            ->willReturn('testImageData');
        $imageContent->expects($this->any())
            ->method('getName')
            ->willReturn('testFileName');
        $imageContent->expects($this->any())
            ->method('getType')
            ->willReturn('image/jpg');

        $imageDataObject = $this->getMockBuilder(\Magento\Framework\Api\AttributeValue::class)
            ->disableOriginalConstructor()
            ->getMock();
        $imageDataObject->expects($this->once())
            ->method('getValue')
            ->willReturn($imageContent);

        $imageData = $this->getMockForAbstractClass(\Magento\Framework\Api\CustomAttributesDataInterface::class);
        $imageData->expects($this->once())
            ->method('getCustomAttributes')
            ->willReturn([]);

        $this->dataObjectHelperMock->expects($this->once())
            ->method('getCustomAttributeValueByType')
            ->willReturn([$imageDataObject]);

        $this->contentValidatorMock->expects($this->once())
            ->method('isValid')
            ->willReturn(true);

        $this->directoryWriteMock->expects($this->any())
            ->method('getAbsolutePath')
            ->willReturn('testPath');

        $this->assertEquals($imageData, $this->imageProcessor->save($imageData, 'testEntityType'));
    }

    public function testSaveWithPreviousData()
    {
        $imageContent = $this->getMockBuilder(\Magento\Framework\Api\Data\ImageContentInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $imageContent->expects($this->any())
            ->method('getBase64EncodedData')
            ->willReturn('testImageData');
        $imageContent->expects($this->any())
            ->method('getName')
            ->willReturn('testFileName.png');

        $imageDataObject = $this->getMockBuilder(\Magento\Framework\Api\AttributeValue::class)
            ->disableOriginalConstructor()
            ->getMock();
        $imageDataObject->expects($this->once())
            ->method('getValue')
            ->willReturn($imageContent);

        $imageData = $this->getMockForAbstractClass(\Magento\Framework\Api\CustomAttributesDataInterface::class);
        $imageData->expects($this->once())
            ->method('getCustomAttributes')
            ->willReturn([]);

        $this->dataObjectHelperMock->expects($this->once())
            ->method('getCustomAttributeValueByType')
            ->willReturn([$imageDataObject]);

        $this->contentValidatorMock->expects($this->once())
            ->method('isValid')
            ->willReturn(true);

        $this->directoryWriteMock->expects($this->any())
            ->method('getAbsolutePath')
            ->willReturn('testPath');

        $prevImageAttribute = $this->getMockForAbstractClass(\Magento\Framework\Api\AttributeInterface::class);
        $prevImageAttribute->expects($this->once())
            ->method('getValue')
            ->willReturn('testImagePath');

        $prevImageData = $this->getMockForAbstractClass(\Magento\Framework\Api\CustomAttributesDataInterface::class);
        $prevImageData->expects($this->once())
            ->method('getCustomAttribute')
            ->willReturn($prevImageAttribute);

        $this->assertEquals($imageData, $this->imageProcessor->save($imageData, 'testEntityType', $prevImageData));
    }

    /**
     */
    public function testSaveWithoutFileExtension()
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);
        $this->expectExceptionMessage('Cannot recognize image extension.');

        $imageContent = $this->getMockBuilder(\Magento\Framework\Api\Data\ImageContentInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $imageContent->expects($this->once())
            ->method('getBase64EncodedData')
            ->willReturn('testImageData');
        $imageContent->expects($this->once())
            ->method('getName')
            ->willReturn('testFileName');

        $imageDataObject = $this->getMockBuilder(\Magento\Framework\Api\AttributeValue::class)
            ->disableOriginalConstructor()
            ->getMock();
        $imageDataObject->expects($this->once())
            ->method('getValue')
            ->willReturn($imageContent);

        $imageData = $this->getMockForAbstractClass(\Magento\Framework\Api\CustomAttributesDataInterface::class);
        $imageData->expects($this->once())
            ->method('getCustomAttributes')
            ->willReturn([]);

        $this->dataObjectHelperMock->expects($this->once())
            ->method('getCustomAttributeValueByType')
            ->willReturn([$imageDataObject]);

        $this->contentValidatorMock->expects($this->once())
            ->method('isValid')
            ->willReturn(true);

        $this->assertEquals($imageData, $this->imageProcessor->save($imageData, 'testEntityType'));
    }
}
