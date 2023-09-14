<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\AsynchronousOperations\Test\Unit\Controller\Adminhtml\Notification;

use Magento\AsynchronousOperations\Controller\Adminhtml\Notification\Dismiss;
use Magento\AsynchronousOperations\Model\BulkNotificationManagement;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DismissTest extends TestCase
{
    /**
     * @var Dismiss
     */
    private $model;

    /**
     * @var MockObject
     */
    private $notificationManagementMock;

    /**
     * @var MockObject
     */
    private $requestMock;

    /**
     * @var MockObject
     */
    private $resultFactoryMock;

    /**
     * @var MockObject
     */
    private $jsonResultMock;

    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);
        $this->notificationManagementMock = $this->createMock(BulkNotificationManagement::class);
        $this->requestMock = $this->getMockForAbstractClass(RequestInterface::class);
        $this->resultFactoryMock = $this->createPartialMock(ResultFactory::class, ['create']);

        $this->jsonResultMock = $this->createMock(Json::class);

        $this->model = $objectManager->getObject(
            Dismiss::class,
            [
                'notificationManagement' => $this->notificationManagementMock,
                'request' => $this->requestMock,
                'resultFactory' => $this->resultFactoryMock,
            ]
        );
    }

    public function testExecute()
    {
        $bulkUuids = ['49da7406-1ec3-4100-95ae-9654c83a6801'];

        $this->requestMock
            ->method('getParam')
            ->with('uuid', [])
            ->willReturn($bulkUuids);

        $this->notificationManagementMock->expects($this->once())
            ->method('acknowledgeBulks')
            ->with($bulkUuids)
            ->willReturn(true);

        $this->resultFactoryMock->expects($this->once())
            ->method('create')
            ->with(ResultFactory::TYPE_JSON, [])
            ->willReturn($this->jsonResultMock);

        $this->jsonResultMock->expects($this->once())
            ->method('setData')
            ->with([''])
            ->willReturn($this->jsonResultMock);

        $this->assertEquals($this->jsonResultMock, $this->model->execute());
    }

    public function testExecuteSetsBadRequestResponseStatusIfBulkWasNotAcknowledgedCorrectly()
    {
        $bulkUuids = ['49da7406-1ec3-4100-95ae-9654c83a6801'];

        $this->requestMock
            ->method('getParam')
            ->with('uuid', [])
            ->willReturn($bulkUuids);

        $this->resultFactoryMock->expects($this->once())
            ->method('create')
            ->with(ResultFactory::TYPE_JSON, [])
            ->willReturn($this->jsonResultMock);

        $this->jsonResultMock->expects($this->once())
            ->method('setData')
            ->with([''])
            ->willReturn($this->jsonResultMock);

        $this->notificationManagementMock->expects($this->once())
            ->method('acknowledgeBulks')
            ->with($bulkUuids)
            ->willReturn(false);

        $this->jsonResultMock->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(400);

        $this->assertEquals($this->jsonResultMock, $this->model->execute());
    }
}
