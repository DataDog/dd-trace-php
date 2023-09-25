<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Config\Test\Unit\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\File;
use Magento\Config\Model\Config\Backend\File\RequestData\RequestDataInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\MediaStorage\Model\File\Uploader;
use Magento\MediaStorage\Model\File\UploaderFactory;

/**
 * Class FileTest
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class FileTest extends \PHPUnit\Framework\TestCase
{
    /** @var File */
    protected $model;

    /** @var Context|\PHPUnit\Framework\MockObject\MockObject */
    protected $contextMock;

    /** @var Registry|\PHPUnit\Framework\MockObject\MockObject */
    protected $registryMock;

    /** @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $scopeConfigMock;

    /** @var TypeListInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $typeListMock;

    /** @var UploaderFactory|\PHPUnit\Framework\MockObject\MockObject */
    protected $uploaderFactoryMock;

    /** @var RequestDataInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $requestDataMock;

    /** @var Filesystem|\PHPUnit\Framework\MockObject\MockObject */
    protected $filesystemMock;

    /** @var WriteInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $writeMock;

    /** @var Uploader|\PHPUnit\Framework\MockObject\MockObject */
    protected $uploaderMock;

    protected function setUp(): void
    {
        $this->contextMock = $this->getMockBuilder(\Magento\Framework\Model\Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->registryMock = $this->getMockBuilder(\Magento\Framework\Registry::class)
            ->getMockForAbstractClass();
        $this->scopeConfigMock = $this->getMockBuilder(\Magento\Framework\App\Config\ScopeConfigInterface::class)
            ->getMockForAbstractClass();
        $this->typeListMock = $this->getMockBuilder(\Magento\Framework\App\Cache\TypeListInterface::class)
            ->getMockForAbstractClass();
        $this->uploaderFactoryMock = $this->getMockBuilder(\Magento\MediaStorage\Model\File\UploaderFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->requestDataMock
            = $this->getMockBuilder(\Magento\Config\Model\Config\Backend\File\RequestData\RequestDataInterface::class)
            ->getMockForAbstractClass();
        $this->filesystemMock = $this->getMockBuilder(\Magento\Framework\Filesystem::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->writeMock = $this->getMockBuilder(\Magento\Framework\Filesystem\Directory\WriteInterface::class)
            ->getMock();
        $this->uploaderMock = $this->getMockBuilder(\Magento\MediaStorage\Model\File\Uploader::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->uploaderFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($this->uploaderMock);

        $this->filesystemMock->expects($this->once())
            ->method('getDirectoryWrite')
            ->with(DirectoryList::MEDIA)
            ->willReturn($this->writeMock);

        $this->model = new File(
            $this->contextMock,
            $this->registryMock,
            $this->scopeConfigMock,
            $this->typeListMock,
            $this->uploaderFactoryMock,
            $this->requestDataMock,
            $this->filesystemMock
        );
    }

    public function testBeforeSave()
    {
        $value = 'value';
        $groupId = 1;
        $field = 'field';
        $tmpFileName = 'tmp_file_name';
        $name = 'name';
        $path = 'path';
        $scope = 'scope';
        $scopeId = 2;
        $uploadDir = 'upload_dir';
        $uploadDirData = [
            'scope_info' => 1,
            'value' => $uploadDir,
        ];
        $fieldConfig = [
            'upload_dir' => $uploadDirData,
        ];
        $fileData = [
            'tmp_name' => $tmpFileName,
            'name' => $name,
        ];
        $fileName = 'file_name';
        $result = [
            'file' => $fileName,
        ];

        $this->model->setValue($value);
        $this->model->setPath($path);
        $this->model->setScope($scope);
        $this->model->setScopeId($scopeId);
        $this->model->setFieldConfig($fieldConfig);
        $_FILES['groups']['tmp_name'][$groupId]['fields'][$field]['value'] = $tmpFileName;

        $this->requestDataMock->expects($this->once())
            ->method('getTmpName')
            ->with($path)
            ->willReturn($tmpFileName);
        $this->requestDataMock->expects($this->once())
            ->method('getName')
            ->with($path)
            ->willReturn($name);

        $this->uploaderFactoryMock->expects($this->any())
            ->method('create')
            ->with(['fileId' => $fileData])
            ->willReturn($this->uploaderMock);
        $this->uploaderMock->expects($this->once())
            ->method('save')
            ->with($uploadDir . '/' . $scope . '/' . $scopeId, null)
            ->willReturn($result);

        $this->assertEquals($this->model, $this->model->beforeSave());
        $this->assertEquals($this->model->getValue(), $scope . '/' . $scopeId . '/' . $fileName);
    }

    public function testBeforeWithoutRequest()
    {
        $tmpFileName = 'tmp_file_name';
        $value = ['tmp_name' => $tmpFileName, 'name' => 'name'];
        $name = 'name';
        $path = 'path';
        $scope = 'scope';
        $scopeId = 2;
        $uploadDir = 'upload_dir';
        $fieldConfig = [
            'upload_dir' => $uploadDir,
        ];
        $fileData = [
            'tmp_name' => $tmpFileName,
            'name' => $name,
        ];
        $fileName = 'file_name';
        $result = [
            'file' => $fileName,
        ];

        $this->model->setValue($value);
        $this->model->setPath($path);
        $this->model->setScope($scope);
        $this->model->setScopeId($scopeId);
        $this->model->setFieldConfig($fieldConfig);

        $this->requestDataMock->expects($this->once())
            ->method('getTmpName')
            ->with($path)
            ->willReturn('');

        $this->uploaderFactoryMock->expects($this->any())
            ->method('create')
            ->with(['fileId' => $fileData])
            ->willReturn($this->uploaderMock);
        $this->uploaderMock->expects($this->once())
            ->method('save')
            ->with($uploadDir, null)
            ->willReturn($result);

        $this->assertEquals($this->model, $this->model->beforeSave());
        $this->assertEquals($this->model->getValue(), $fileName);
    }

    public function testBeforeWithoutFile()
    {
        $value = ['name' => 'name'];
        $path = 'path';
        $uploadDir = 'upload_dir';
        $uploadDirData = [
            'scope_info' => 1,
            'value' => $uploadDir,
        ];
        $fieldConfig = [
            'upload_dir' => $uploadDirData,
        ];

        $this->model->setValue($value);
        $this->model->setPath($path);
        $this->model->setFieldConfig($fieldConfig);

        $this->requestDataMock->expects($this->once())
            ->method('getTmpName')
            ->with($path)
            ->willReturn('');

        $this->assertEquals($this->model, $this->model->beforeSave());
        $this->assertEquals($this->model->getValue(), null);
    }

    public function testBeforeWithDelete()
    {
        $value = ['delete' => 1];
        $path = 'path';
        $uploadDir = 'upload_dir';
        $uploadDirData = [
            'scope_info' => 1,
            'value' => $uploadDir,
        ];
        $fieldConfig = [
            'upload_dir' => $uploadDirData,
        ];

        $this->model->setValue($value);
        $this->model->setPath($path);
        $this->model->setFieldConfig($fieldConfig);

        $this->requestDataMock->expects($this->once())
            ->method('getTmpName')
            ->with($path)
            ->willReturn('');

        $this->assertEquals($this->model, $this->model->beforeSave());
        $this->assertEquals($this->model->getValue(), '');
    }

    /**
     */
    public function testBeforeSaveWithException()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('Exception!');

        $value = 'value';
        $groupId = 1;
        $field = 'field';
        $tmpFileName = 'tmp_file_name';
        $name = 'name';
        $path = 'path';
        $scope = 'scope';
        $scopeId = 2;
        $uploadDir = 'upload_dir';
        $fieldConfig = [
            'upload_dir' => $uploadDir,
        ];
        $fileData = [
            'tmp_name' => $tmpFileName,
            'name' => $name,
        ];
        $exception = 'Exception!';

        $this->model->setValue($value);
        $this->model->setPath($path);
        $this->model->setScope($scope);
        $this->model->setScopeId($scopeId);
        $this->model->setFieldConfig($fieldConfig);
        $_FILES['groups']['tmp_name'][$groupId]['fields'][$field]['value'] = $tmpFileName;

        $this->requestDataMock->expects($this->once())
            ->method('getTmpName')
            ->with($path)
            ->willReturn($tmpFileName);
        $this->requestDataMock->expects($this->once())
            ->method('getName')
            ->with($path)
            ->willReturn($name);

        $this->uploaderFactoryMock->expects($this->any())
            ->method('create')
            ->with(['fileId' => $fileData])
            ->willReturn($this->uploaderMock);
        $this->uploaderMock->expects($this->once())
            ->method('save')
            ->with($uploadDir, null)
            ->willThrowException(new \Exception($exception));

        $this->model->beforeSave();
    }
}
