<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\CatalogInventory\Test\Unit\Model\Stock;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\Data as InventoryApiData;
use Magento\CatalogInventory\Model\Stock\StockItemRepository;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class StockItemRepositoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var StockItemRepository
     */
    private $model;

    /**
     * @var \Magento\CatalogInventory\Model\Stock\Item |\PHPUnit\Framework\MockObject\MockObject
     */
    private $stockItemMock;

    /**
     * @var \Magento\CatalogInventory\Api\StockConfigurationInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $stockConfigurationMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $productMock;

    /**
     * @var \Magento\CatalogInventory\Model\Spi\StockStateProviderInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $stockStateProviderMock;

    /**
     * @var \Magento\CatalogInventory\Model\ResourceModel\Stock\Item|\PHPUnit\Framework\MockObject\MockObject
     */
    private $stockItemResourceMock;

    /**
     * @var InventoryApiData\StockItemInterfaceFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $stockItemFactoryMock;

    /**
     * @var InventoryApiData\StockItemCollectionInterfaceFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $stockItemCollectionMock;

    /**
     * @var \Magento\Catalog\Model\ProductFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $productFactoryMock;

    /**
     * @var \Magento\Framework\DB\QueryBuilderFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $queryBuilderFactoryMock;

    /**
     * @var \Magento\Framework\DB\MapperFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $mapperMock;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\TimezoneInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $localeDateMock;

    /**
     * @var \Magento\CatalogInventory\Model\Indexer\Stock\Processor|\PHPUnit\Framework\MockObject\MockObject
     */
    private $indexProcessorMock;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime|\PHPUnit\Framework\MockObject\MockObject
     */
    private $dateTime;

    /**
     * @var StockRegistryStorage|\PHPUnit\Framework\MockObject\MockObject
     */
    private $stockRegistryStorage;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function setUp(): void
    {
        $this->stockItemMock = $this->getMockBuilder(\Magento\CatalogInventory\Model\Stock\Item::class)
            ->disableOriginalConstructor()
            ->setMethods(
                [
                    'getItemId',
                    'getProductId',
                    'setIsInStock',
                    'setStockStatusChangedAutomaticallyFlag',
                    'getStockStatusChangedAutomaticallyFlag',
                    'getManageStock',
                    'setLowStockDate',
                    'setStockStatusChangedAuto',
                    'hasStockStatusChangedAutomaticallyFlag',
                    'setQty',
                    'getWebsiteId',
                    'setWebsiteId',
                    'getStockId',
                    'setStockId'
                ]
            )
            ->getMock();
        $this->stockConfigurationMock = $this->getMockBuilder(
            \Magento\CatalogInventory\Api\StockConfigurationInterface::class
        )
            ->disableOriginalConstructor()
            ->getMock();
        $this->stockStateProviderMock = $this->getMockBuilder(
            \Magento\CatalogInventory\Model\Spi\StockStateProviderInterface::class
        )
            ->disableOriginalConstructor()
            ->getMock();
        $this->stockItemResourceMock = $this->getMockBuilder(
            \Magento\CatalogInventory\Model\ResourceModel\Stock\Item::class
        )
            ->disableOriginalConstructor()
            ->getMock();
        $this->stockItemFactoryMock = $this->getMockBuilder(
            \Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory::class
        )
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->stockItemCollectionMock = $this->getMockBuilder(
            \Magento\CatalogInventory\Api\Data\StockItemCollectionInterfaceFactory::class
        )
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->productFactoryMock = $this->getMockBuilder(\Magento\Catalog\Model\ProductFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['load', 'create'])
            ->getMock();
        $this->productMock = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->disableOriginalConstructor()
            ->setMethods(['load', 'getId', 'getTypeId', '__wakeup'])
            ->getMock();

        $this->productFactoryMock->expects($this->any())->method('create')->willReturn($this->productMock);

        $this->queryBuilderFactoryMock = $this->getMockBuilder(\Magento\Framework\DB\QueryBuilderFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->mapperMock = $this->getMockBuilder(\Magento\Framework\DB\MapperFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->localeDateMock = $this->getMockBuilder(\Magento\Framework\Stdlib\DateTime\TimezoneInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->indexProcessorMock = $this->createPartialMock(
            \Magento\CatalogInventory\Model\Indexer\Stock\Processor::class,
            ['reindexRow']
        );
        $this->dateTime = $this->createPartialMock(\Magento\Framework\Stdlib\DateTime\DateTime::class, ['gmtDate']);
        $this->stockRegistryStorage = $this->getMockBuilder(StockRegistryStorage::class)
            ->disableOriginalConstructor()
            ->getMock();

        $productCollection = $this->getMockBuilder(
            \Magento\Catalog\Model\ResourceModel\Product\Collection::class
        )->disableOriginalConstructor()->getMock();

        $productCollection->expects($this->any())->method('setFlag')->willReturnSelf();
        $productCollection->expects($this->any())->method('addIdFilter')->willReturnSelf();
        $productCollection->expects($this->any())->method('addFieldToSelect')->willReturnSelf();
        $productCollection->expects($this->any())->method('getFirstItem')->willReturn($this->productMock);

        $productCollectionFactory = $this->getMockBuilder(CollectionFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();

        $productCollectionFactory->expects($this->any())->method('create')->willReturn($productCollection);

        $this->model = (new ObjectManager($this))->getObject(
            StockItemRepository::class,
            [
                'stockConfiguration' => $this->stockConfigurationMock,
                'stockStateProvider' => $this->stockStateProviderMock,
                'resource' => $this->stockItemResourceMock,
                'stockItemFactory' => $this->stockItemFactoryMock,
                'stockItemCollectionFactory' => $this->stockItemCollectionMock,
                'productFactory' => $this->productFactoryMock,
                'queryBuilderFactory' => $this->queryBuilderFactoryMock,
                'mapperFactory' => $this->mapperMock,
                'localeDate' => $this->localeDateMock,
                'indexProcessor' => $this->indexProcessorMock,
                'dateTime' => $this->dateTime,
                'stockRegistryStorage' => $this->stockRegistryStorage,
                'productCollectionFactory' => $productCollectionFactory,
            ]
        );
    }

    public function testDelete()
    {
        $productId = 1;
        $this->stockItemMock->expects($this->atLeastOnce())->method('getProductId')->willReturn($productId);
        $this->stockRegistryStorage->expects($this->once())->method('removeStockItem')->with($productId);
        $this->stockRegistryStorage->expects($this->once())->method('removeStockStatus')->with($productId);

        $this->stockItemResourceMock->expects($this->once())
            ->method('delete')
            ->with($this->stockItemMock)
            ->willReturnSelf();

        $this->assertTrue($this->model->delete($this->stockItemMock));
    }

    /**
     */
    public function testDeleteException()
    {
        $this->expectException(\Magento\Framework\Exception\CouldNotDeleteException::class);

        $this->stockItemResourceMock->expects($this->once())
            ->method('delete')
            ->with($this->stockItemMock)
            ->willThrowException(new \Exception());

        $this->model->delete($this->stockItemMock);
    }

    public function testDeleteById()
    {
        $id = 1;

        $this->stockItemFactoryMock->expects($this->once())->method('create')->willReturn($this->stockItemMock);
        $this->stockItemResourceMock->expects($this->once())->method('load')->with($this->stockItemMock, $id);
        $this->stockItemMock->expects($this->once())->method('getItemId')->willReturn($id);

        $this->assertTrue($this->model->deleteById($id));
    }

    /**
     */
    public function testDeleteByIdException()
    {
        $this->expectException(\Magento\Framework\Exception\CouldNotDeleteException::class);
        $this->expectExceptionMessage('The stock item with the "1" ID wasn\'t found. Verify the ID and try again.');

        $id = 1;

        $this->stockItemFactoryMock->expects($this->once())->method('create')->willReturn($this->stockItemMock);
        $this->stockItemResourceMock->expects($this->once())->method('load')->with($this->stockItemMock, $id);
        $this->stockItemMock->expects($this->once())->method('getItemId')->willReturn(null);

        $this->assertTrue($this->model->deleteById($id));
    }

    public function testSave()
    {
        $productId = 1;

        $this->stockItemMock->expects($this->any())->method('getProductId')->willReturn($productId);
        $this->productMock->expects($this->once())->method('getId')->willReturn($productId);
        $this->productMock->expects($this->once())->method('getTypeId')->willReturn('typeId');
        $this->stockConfigurationMock->expects($this->once())->method('isQty')->with('typeId')->willReturn(true);
        $this->stockStateProviderMock->expects($this->once())
            ->method('verifyStock')
            ->with($this->stockItemMock)
            ->willReturn(false);
        $this->stockItemMock->expects($this->once())->method('getManageStock')->willReturn(true);
        $this->stockItemMock->expects($this->once())->method('setIsInStock')->with(false)->willReturnSelf();
        $this->stockItemMock->expects($this->once())
            ->method('setStockStatusChangedAutomaticallyFlag')
            ->with(true)
            ->willReturnSelf();
        $this->stockItemMock->expects($this->any())->method('setLowStockDate')->willReturnSelf();
        $this->stockStateProviderMock->expects($this->once())
            ->method('verifyNotification')
            ->with($this->stockItemMock)
            ->willReturn(true);
        $this->dateTime->expects($this->once())
            ->method('gmtDate');
        $this->stockItemMock->expects($this->atLeastOnce())->method('setStockStatusChangedAuto')->willReturnSelf();
        $this->stockItemMock->expects($this->once())
            ->method('hasStockStatusChangedAutomaticallyFlag')
            ->willReturn(true);
        $this->stockItemMock->expects($this->once())
            ->method('getStockStatusChangedAutomaticallyFlag')
            ->willReturn(true);
        $this->stockItemMock->expects($this->once())->method('getWebsiteId')->willReturn(1);
        $this->stockItemMock->expects($this->once())->method('setWebsiteId')->with(1)->willReturnSelf();
        $this->stockItemMock->expects($this->once())->method('getStockId')->willReturn(1);
        $this->stockItemMock->expects($this->once())->method('setStockId')->with(1)->willReturnSelf();
        $this->stockItemResourceMock->expects($this->once())
            ->method('save')
            ->with($this->stockItemMock)
            ->willReturnSelf();

        $this->assertEquals($this->stockItemMock, $this->model->save($this->stockItemMock));
    }

    public function testSaveWithoutProductId()
    {
        $productId = 1;

        $this->stockItemMock->expects($this->any())->method('getProductId')->willReturn($productId);
        $this->productMock->expects($this->once())->method('getId')->willReturn(null);
        $this->stockRegistryStorage->expects($this->never())->method('removeStockItem');
        $this->stockRegistryStorage->expects($this->never())->method('removeStockStatus');

        $this->assertEquals($this->stockItemMock, $this->model->save($this->stockItemMock));
    }

    /**
     */
    public function testSaveException()
    {
        $this->expectException(\Magento\Framework\Exception\CouldNotSaveException::class);

        $productId = 1;

        $this->stockItemMock->expects($this->any())->method('getProductId')->willReturn($productId);
        $this->productMock->expects($this->once())->method('getId')->willReturn($productId);
        $this->productMock->expects($this->once())->method('getTypeId')->willReturn('typeId');
        $this->stockConfigurationMock->expects($this->once())->method('isQty')->with('typeId')->willReturn(false);
        $this->stockItemMock->expects($this->once())->method('setQty')->with(0)->willReturnSelf();
        $this->stockItemMock->expects($this->once())->method('getWebsiteId')->willReturn(1);
        $this->stockItemMock->expects($this->once())->method('setWebsiteId')->with(1)->willReturnSelf();
        $this->stockItemMock->expects($this->once())->method('getStockId')->willReturn(1);
        $this->stockItemMock->expects($this->once())->method('setStockId')->with(1)->willReturnSelf();
        $this->stockItemResourceMock->expects($this->once())
            ->method('save')
            ->with($this->stockItemMock)
            ->willThrowException(new \Exception());

        $this->model->save($this->stockItemMock);
    }

    public function testGetList()
    {
        $criteriaMock = $this->getMockBuilder(\Magento\CatalogInventory\Api\StockItemCriteriaInterface::class)
            ->getMock();
        $queryBuilderMock = $this->getMockBuilder(\Magento\Framework\DB\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->setMethods(['setCriteria', 'setResource', 'create'])
            ->getMock();
        $queryMock = $this->getMockBuilder(\Magento\Framework\DB\QueryInterface::class)
            ->getMock();
        $queryCollectionMock = $this->getMockBuilder(
            \Magento\CatalogInventory\Api\Data\StockItemCollectionInterface::class
        )->getMock();

        $this->queryBuilderFactoryMock->expects($this->once())->method('create')->willReturn($queryBuilderMock);
        $queryBuilderMock->expects($this->once())->method('setCriteria')->with($criteriaMock)->willReturnSelf();
        $queryBuilderMock->expects($this->once())
            ->method('setResource')
            ->with($this->stockItemResourceMock)
            ->willReturnSelf();
        $queryBuilderMock->expects($this->once())->method('create')->willReturn($queryMock);
        $this->stockItemCollectionMock->expects($this->once())->method('create')->willReturn($queryCollectionMock);

        $this->assertEquals($queryCollectionMock, $this->model->getList($criteriaMock));
    }
}
