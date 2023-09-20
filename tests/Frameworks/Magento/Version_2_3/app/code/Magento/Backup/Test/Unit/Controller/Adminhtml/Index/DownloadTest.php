<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backup\Test\Unit\Controller\Adminhtml\Index;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * @covers \Magento\Backup\Controller\Adminhtml\Index\Download
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DownloadTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManager;

    /**
     * @var \Magento\Backend\App\Action\Context
     */
    protected $context;

    /**
     * @var \Magento\Backup\Controller\Adminhtml\Index\Download
     */
    protected $downloadController;

    /**
     * @var \Magento\Framework\ObjectManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectManagerMock;

    /**
     * @var \Magento\Framework\App\RequestInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $requestMock;

    /**
     * @var \Magento\Framework\App\ResponseInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $responseMock;

    /**
     * @var \Magento\Backup\Model\BackupFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $backupModelFactoryMock;

    /**
     * @var \Magento\Backup\Model\Backup|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $backupModelMock;

    /**
     * @var \Magento\Backup\Helper\Data|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $dataHelperMock;

    /**
     * @var \Magento\Framework\App\Response\Http\FileFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $fileFactoryMock;

    /**
     * @var \Magento\Framework\Controller\Result\RawFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultRawFactoryMock;

    /**
     * @var \Magento\Backend\Model\View\Result\RedirectFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultRedirectFactoryMock;

    /**
     * @var \Magento\Framework\Controller\Result\Raw|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultRawMock;

    /**
     * @var \Magento\Backend\Model\View\Result\Redirect|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultRedirectMock;

    protected function setUp(): void
    {
        $this->objectManagerMock = $this->getMockBuilder(\Magento\Framework\ObjectManagerInterface::class)
            ->getMock();
        $this->requestMock = $this->getMockBuilder(\Magento\Framework\App\RequestInterface::class)
            ->getMock();
        $this->responseMock = $this->getMockBuilder(\Magento\Framework\App\ResponseInterface::class)
            ->getMock();
        $this->backupModelFactoryMock = $this->getMockBuilder(\Magento\Backup\Model\BackupFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->backupModelMock = $this->getMockBuilder(\Magento\Backup\Model\Backup::class)
            ->disableOriginalConstructor()
            ->setMethods(['getTime', 'exists', 'getSize', 'output'])
            ->getMock();
        $this->dataHelperMock = $this->getMockBuilder(\Magento\Backup\Helper\Data::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->fileFactoryMock = $this->getMockBuilder(\Magento\Framework\App\Response\Http\FileFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resultRawFactoryMock = $this->getMockBuilder(\Magento\Framework\Controller\Result\RawFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->resultRedirectFactoryMock = $this->getMockBuilder(
            \Magento\Backend\Model\View\Result\RedirectFactory::class
        )->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->resultRawMock = $this->getMockBuilder(\Magento\Framework\Controller\Result\Raw::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resultRedirectMock = $this->getMockBuilder(\Magento\Backend\Model\View\Result\Redirect::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->objectManager = new ObjectManager($this);
        $this->context = $this->objectManager->getObject(
            \Magento\Backend\App\Action\Context::class,
            [
                'objectManager' => $this->objectManagerMock,
                'request' => $this->requestMock,
                'response' => $this->responseMock,
                'resultRedirectFactory' => $this->resultRedirectFactoryMock
            ]
        );
        $this->downloadController = $this->objectManager->getObject(
            \Magento\Backup\Controller\Adminhtml\Index\Download::class,
            [
                'context' => $this->context,
                'backupModelFactory' => $this->backupModelFactoryMock,
                'fileFactory' => $this->fileFactoryMock,
                'resultRawFactory' => $this->resultRawFactoryMock,
            ]
        );
    }

    /**
     * @covers \Magento\Backup\Controller\Adminhtml\Index\Download::execute
     */
    public function testExecuteBackupFound()
    {
        $time = 1;
        $type = 'db';
        $filename = 'filename';
        $size = 10;
        $output = 'test';

        $this->backupModelMock->expects($this->atLeastOnce())
            ->method('getTime')
            ->willReturn($time);
        $this->backupModelMock->expects($this->atLeastOnce())
            ->method('exists')
            ->willReturn(true);
        $this->backupModelMock->expects($this->atLeastOnce())
            ->method('getSize')
            ->willReturn($size);
        $this->backupModelMock->expects($this->atLeastOnce())
            ->method('output')
            ->willReturn($output);
        $this->requestMock->expects($this->any())
            ->method('getParam')
            ->willReturnMap(
                [
                    ['time', null, $time],
                    ['type', null, $type]
                ]
            );
        $this->backupModelFactoryMock->expects($this->once())
            ->method('create')
            ->with($time, $type)
            ->willReturn($this->backupModelMock);
        $this->dataHelperMock->expects($this->once())
            ->method('generateBackupDownloadName')
            ->with($this->backupModelMock)
            ->willReturn($filename);
        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->with(\Magento\Backup\Helper\Data::class)
            ->willReturn($this->dataHelperMock);
        $this->fileFactoryMock->expects($this->once())
            ->method('create')->with(
                $filename,
                null,
                DirectoryList::VAR_DIR,
                'application/octet-stream',
                $size
            )
            ->willReturn($this->responseMock);
        $this->resultRawMock->expects($this->once())
            ->method('setContents')
            ->with($output);
        $this->resultRawFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->resultRawMock);

        $this->assertSame($this->resultRawMock, $this->downloadController->execute());
    }

    /**
     * @covers \Magento\Backup\Controller\Adminhtml\Index\Download::execute
     * @param int $time
     * @param bool $exists
     * @param int $existsCount
     * @dataProvider executeBackupNotFoundDataProvider
     */
    public function testExecuteBackupNotFound($time, $exists, $existsCount)
    {
        $type = 'db';

        $this->backupModelMock->expects($this->atLeastOnce())
            ->method('getTime')
            ->willReturn($time);
        $this->backupModelMock->expects($this->exactly($existsCount))
            ->method('exists')
            ->willReturn($exists);
        $this->requestMock->expects($this->any())
            ->method('getParam')
            ->willReturnMap(
                [
                    ['time', null, $time],
                    ['type', null, $type]
                ]
            );
        $this->backupModelFactoryMock->expects($this->once())
            ->method('create')
            ->with($time, $type)
            ->willReturn($this->backupModelMock);
        $this->resultRedirectMock->expects($this->once())
            ->method('setPath')
            ->with('backup/*');
        $this->resultRedirectFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->resultRedirectMock);

        $this->assertSame($this->resultRedirectMock, $this->downloadController->execute());
    }

    /**
     * @return array
     */
    public function executeBackupNotFoundDataProvider()
    {
        return [
            [1, false, 1],
            [0, true, 0],
            [0, false, 0]
        ];
    }
}
