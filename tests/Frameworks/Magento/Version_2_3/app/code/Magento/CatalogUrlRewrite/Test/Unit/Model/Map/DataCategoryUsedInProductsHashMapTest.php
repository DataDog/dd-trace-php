<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogUrlRewrite\Test\Unit\Model\Map;

use Magento\Framework\DB\Select;
use Magento\CatalogUrlRewrite\Model\Map\HashMapPool;
use Magento\CatalogUrlRewrite\Model\Map\DataProductHashMap;
use Magento\CatalogUrlRewrite\Model\Map\DataCategoryHashMap;
use Magento\CatalogUrlRewrite\Model\Map\DataCategoryUsedInProductsHashMap;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Class DataCategoryUsedInProductsHashMapTest
 */
class DataCategoryUsedInProductsHashMapTest extends \PHPUnit\Framework\TestCase
{
    /** @var HashMapPool|\PHPUnit\Framework\MockObject\MockObject */
    private $hashMapPoolMock;

    /** @var DataCategoryHashMap|\PHPUnit\Framework\MockObject\MockObject */
    private $dataCategoryMapMock;

    /** @var DataProductHashMap|\PHPUnit\Framework\MockObject\MockObject */
    private $dataProductMapMock;

    /** @var ResourceConnection|\PHPUnit\Framework\MockObject\MockObject */
    private $connectionMock;

    /** @var DataCategoryUsedInProductsHashMap|\PHPUnit\Framework\MockObject\MockObject */
    private $model;

    protected function setUp(): void
    {
        $this->hashMapPoolMock = $this->createMock(HashMapPool::class);
        $this->dataCategoryMapMock = $this->createMock(DataCategoryHashMap::class);
        $this->dataProductMapMock = $this->createMock(DataProductHashMap::class);
        $this->connectionMock = $this->createMock(ResourceConnection::class);

        $this->hashMapPoolMock->expects($this->any())
            ->method('getDataMap')
            ->willReturnOnConsecutiveCalls(
                $this->dataProductMapMock,
                $this->dataCategoryMapMock,
                $this->dataProductMapMock,
                $this->dataCategoryMapMock,
                $this->dataProductMapMock,
                $this->dataCategoryMapMock
            );

        $this->model = (new ObjectManager($this))->getObject(
            DataCategoryUsedInProductsHashMap::class,
            [
                'connection' => $this->connectionMock,
                'hashMapPool' => $this->hashMapPoolMock
            ]
        );
    }

    /**
     * Tests getAllData, getData and resetData functionality
     */
    public function testGetAllData()
    {
        $categoryIds = ['1' => [1, 2, 3], '2' => [2, 3], '3' => 3];
        $categoryIdsOther = ['2' => [2, 3, 4]];

        $connectionMock = $this->getMockForAbstractClass(AdapterInterface::class);
        $selectMock = $this->createMock(Select::class);

        $this->connectionMock->expects($this->any())
            ->method('getConnection')
            ->willReturn($connectionMock);
        $connectionMock->expects($this->any())
            ->method('select')
            ->willReturn($selectMock);
        $connectionMock->expects($this->any())
            ->method('fetchCol')
            ->willReturnOnConsecutiveCalls($categoryIds, $categoryIdsOther, $categoryIds);
        $selectMock->expects($this->any())
            ->method('from')
            ->willReturnSelf();
        $selectMock->expects($this->any())
            ->method('joinInner')
            ->willReturnSelf();
        $selectMock->expects($this->any())
            ->method('where')
            ->willReturnSelf();
        $this->hashMapPoolMock->expects($this->at(4))
            ->method('resetMap')
            ->with(DataProductHashMap::class, 1);
        $this->hashMapPoolMock->expects($this->at(5))
            ->method('resetMap')
            ->with(DataCategoryHashMap::class, 1);

        $this->assertEquals($categoryIds, $this->model->getAllData(1));
        $this->assertEquals($categoryIds[2], $this->model->getData(1, 2));
        $this->assertEquals($categoryIdsOther, $this->model->getAllData(2));
        $this->assertEquals($categoryIdsOther[2], $this->model->getData(2, 2));
        $this->model->resetData(1);
        $this->assertEquals($categoryIds[2], $this->model->getData(1, 2));
        $this->assertEquals($categoryIds, $this->model->getAllData(1));
    }
}
