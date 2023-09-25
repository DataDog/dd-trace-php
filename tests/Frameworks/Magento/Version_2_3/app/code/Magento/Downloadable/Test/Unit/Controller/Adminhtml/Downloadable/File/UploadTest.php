<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Downloadable\Test\Unit\Controller\Adminhtml\Downloadable\File;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class UploadTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Downloadable\Controller\Adminhtml\Downloadable\File\Upload */
    protected $upload;

    /** @var ObjectManagerHelper */
    protected $objectManagerHelper;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\App\ResponseInterface
     */
    protected $response;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Downloadable\Model\Link
     */
    protected $link;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Downloadable\Model\Sample
     */
    protected $sample;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Backend\App\Action\Context
     */
    protected $context;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\MediaStorage\Model\File\UploaderFactory
     */
    private $uploaderFactory;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\MediaStorage\Helper\File\Storage\Database
     */
    private $storageDatabase;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Downloadable\Helper\File
     */
    protected $fileHelper;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\Controller\ResultFactory
     */
    protected $resultFactory;

    protected function setUp(): void
    {
        $this->objectManagerHelper = new ObjectManagerHelper($this);

        $this->storageDatabase = $this->getMockBuilder(\Magento\MediaStorage\Helper\File\Storage\Database::class)
            ->disableOriginalConstructor()
            ->setMethods(['saveFile'])
            ->getMock();
        $this->uploaderFactory = $this->getMockBuilder(\Magento\MediaStorage\Model\File\UploaderFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->resultFactory = $this->getMockBuilder(\Magento\Framework\Controller\ResultFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->context = $this->getMockBuilder(\Magento\Backend\App\Action\Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->request = $this->createMock(\Magento\Framework\App\RequestInterface::class);
        $this->response = $this->createPartialMock(
            \Magento\Framework\App\ResponseInterface::class,
            [
                'setHttpResponseCode',
                'clearBody',
                'sendHeaders',
                'sendResponse',
                'setHeader'
            ]
        );
        $this->fileHelper = $this->createPartialMock(\Magento\Downloadable\Helper\File::class, [
                'uploadFromTmp'
            ]);
        $this->context->expects($this->any())
            ->method('getRequest')
            ->willReturn($this->request);
        $this->context->expects($this->any())
            ->method('getResultFactory')
            ->willReturn($this->resultFactory);

        $this->link = $this->getMockBuilder(\Magento\Downloadable\Model\Link::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->sample = $this->getMockBuilder(\Magento\Downloadable\Model\Sample::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->upload = $this->objectManagerHelper->getObject(
            \Magento\Downloadable\Controller\Adminhtml\Downloadable\File\Upload::class,
            [
                'context' => $this->context,
                'link' => $this->link,
                'sample' => $this->sample,
                'fileHelper' => $this->fileHelper,
                'uploaderFactory' => $this->uploaderFactory,
                'storageDatabase' => $this->storageDatabase
            ]
        );
    }

    public function testExecute()
    {
        $data = [
            'tmp_name' => 'tmp_name',
            'path' => 'path',
            'file' => 'file'
        ];
        $uploader = $this->getMockBuilder(\Magento\MediaStorage\Model\File\Uploader::class)
            ->disableOriginalConstructor()
            ->getMock();
        $resultJson = $this->getMockBuilder(\Magento\Framework\Controller\Result\Json::class)
            ->disableOriginalConstructor()
            ->setMethods(['setData'])
            ->getMock();
        $this->request->expects($this->once())->method('getParam')->with('type')->willReturn('samples');
        $this->sample->expects($this->once())->method('getBaseTmpPath')->willReturn('base_tmp_path');
        $this->uploaderFactory->expects($this->once())->method('create')->willReturn($uploader);
        $this->fileHelper->expects($this->once())->method('uploadFromTmp')->willReturn($data);
        $this->storageDatabase->expects($this->once())->method('saveFile');
        $this->resultFactory->expects($this->once())->method('create')->willReturn($resultJson);
        $resultJson->expects($this->once())->method('setData')->willReturnSelf();

        $this->assertEquals($resultJson, $this->upload->execute());
    }
}
