<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Security\Test\Unit\Model\ResourceModel;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * Test class for \Magento\Security\Model\ResourceModel\AdminSessionInfo testing
 */
class AdminSessionInfoTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Security\Model\ResourceModel\AdminSessionInfo */
    protected $model;

    /** @var \Magento\Framework\Stdlib\DateTime */
    protected $dateTimeMock;

    /** @var \Magento\Framework\App\ResourceConnection */
    protected $resourceMock;

    /** @var \Magento\Framework\DB\Adapter\AdapterInterface */
    protected $dbAdapterMock;

    /**
     * Init mocks for tests
     * @return void
     */
    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);

        $this->dateTimeMock = $this->createMock(\Magento\Framework\Stdlib\DateTime::class);

        $this->resourceMock = $this->createMock(\Magento\Framework\App\ResourceConnection::class);

        $this->dbAdapterMock = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);

        $this->model = $objectManager->getObject(
            \Magento\Security\Model\ResourceModel\AdminSessionInfo::class,
            [
                'resource' => $this->resourceMock,
                'dateTime' => $this->dateTimeMock
            ]
        );
    }

    /**
     * @return void
     */
    public function testDeleteSessionsOlderThen()
    {
        $timestamp = 12345;

        $this->resourceMock->expects($this->once())
            ->method('getConnection')
            ->willReturn($this->dbAdapterMock);

        $this->dbAdapterMock->expects($this->once())
            ->method('delete')
            ->with($this->model->getMainTable(), ['updated_at < ?' => $this->dateTimeMock->formatDate($timestamp)])
            ->willReturnSelf();

        $this->assertEquals($this->model, $this->model->deleteSessionsOlderThen($timestamp));
    }

    /**
     * @return void
     */
    public function testUpdateStatusByUserId()
    {
        $status = 2;
        $userId = 10;
        $withStatuses = [1, 5];
        $excludedSessionIds = [20, 21, 22];
        $updateOlderThen = '2015-12-31 23:59:59';

        $whereStatement = [
            'updated_at > ?' => $this->dateTimeMock->formatDate($updateOlderThen),
            'user_id = ?' => (int) $userId,
        ];
        if (!empty($excludedSessionIds)) {
            $whereStatement['id NOT IN (?)'] = $excludedSessionIds;
        }
        if (!empty($withStatuses)) {
            $whereStatement['status IN (?)'] = $withStatuses;
        }

        $this->resourceMock->expects($this->once())
            ->method('getConnection')
            ->willReturn($this->dbAdapterMock);

        $this->dbAdapterMock->expects($this->once())
            ->method('update')
            ->with($this->model->getMainTable(), ['status' => $status], $whereStatement)
            ->willReturnSelf();

        $this->model->updateStatusByUserId($status, $userId, $withStatuses, $excludedSessionIds, $updateOlderThen);
    }
}
