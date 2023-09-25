<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Weee\Test\Unit\Model\ResourceModel;

class TaxTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Weee\Model\ResourceModel\Tax
     */
    protected $model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $resourceMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $storeManagerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $connectionMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $selectMock;

    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->storeManagerMock = $this->createMock(\Magento\Store\Model\StoreManagerInterface::class);

        $this->selectMock = $this->createMock(\Magento\Framework\DB\Select::class);

        $this->connectionMock = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $this->connectionMock->expects($this->once())
            ->method('select')
            ->willReturn($this->selectMock);

        $this->resourceMock = $this->createMock(\Magento\Framework\App\ResourceConnection::class);
        $this->resourceMock->expects($this->any())
            ->method('getConnection')
            ->willReturn($this->connectionMock);

        $this->resourceMock->expects($this->atLeastOnce())
            ->method('getTableName')
            ->willReturn('table_name');

        $contextMock = $this->createMock(\Magento\Framework\Model\ResourceModel\Db\Context::class);
        $contextMock->expects($this->any())->method('getResources')->willReturn($this->resourceMock);

        $this->model = $this->objectManager->getObject(
            \Magento\Weee\Model\ResourceModel\Tax::class,
            [
                'context' => $contextMock,
            ]
        );
    }

    public function testInWeeeLocation()
    {
        $this->selectMock->expects($this->at(1))
            ->method('where')
            ->with('website_id IN(?)', [1, 0])
            ->willReturn($this->selectMock);

        $this->selectMock->expects($this->at(2))
            ->method('where')
            ->with('country = ?', 'US')
            ->willReturn($this->selectMock);

        $this->selectMock->expects($this->at(3))
            ->method('where')
            ->with('state = ?', 0)
            ->willReturn($this->selectMock);

        $this->selectMock->expects($this->any())
            ->method('from')
            ->with('table_name', 'value')
            ->willReturn($this->selectMock);

        $this->model->isWeeeInLocation('US', 0, 1);
    }

    public function testFetchWeeeTaxCalculationsByEntity()
    {
        $this->selectMock->expects($this->any())
            ->method('where')
            ->willReturn($this->selectMock);

        $this->selectMock->expects($this->any())
            ->method('from')
            ->with(
                ['eavTable' => 'table_name'],
                [
                    'eavTable.attribute_code',
                    'eavTable.attribute_id',
                    'eavTable.frontend_label'
                ]
            )->willReturn($this->selectMock);

        $this->selectMock->expects($this->any())
            ->method('joinLeft')
            ->willReturn($this->selectMock);

        $this->selectMock->expects($this->any())
            ->method('joinInner')
            ->willReturn($this->selectMock);

        $this->model->fetchWeeeTaxCalculationsByEntity('US', 0, 1, 3, 4);
    }
}
