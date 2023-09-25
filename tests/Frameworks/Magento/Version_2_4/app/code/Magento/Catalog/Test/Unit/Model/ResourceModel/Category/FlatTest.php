<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Model\ResourceModel\Category;

use Magento\Catalog\Model\ResourceModel\Category\Flat;
use Magento\Catalog\Model\ResourceModel\Category\Flat\Collection;
use Magento\Catalog\Model\ResourceModel\Category\Flat\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface as Adapter;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class FlatTest extends TestCase
{
    const STORE_ID = 1;
    const TABLE_NAME = 'test_table';
    const PARENT_PATH = '1';
    const SORTED = false;
    const PARENT = 1;
    const RECURSION_LEVEL = 0;

    /**
     * @var CollectionFactory|MockObject
     */
    private $categoryCollectionFactoryMock;

    /**
     * @var Collection|MockObject
     */
    private $categoryCollectionMock;

    /**
     * @var Flat
     */
    private $model;

    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var Select|MockObject
     */
    private $selectMock;

    /**
     * @var Adapter|MockObject
     */
    private $connectionMock;

    /**
     * @var ResourceConnection|MockObject
     */
    private $resourceMock;

    /**
     * @var Context|MockObject
     */
    private $contextMock;

    /**
     * @var Store|MockObject
     */
    private $storeMock;

    /**
     * @var StoreManagerInterface|MockObject
     */
    private $storeManagerMock;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);

        $this->selectMock = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->setMethods(['where', 'from'])
            ->getMock();
        $this->selectMock->expects($this->once())
            ->method('where')
            ->willReturn($this->selectMock);
        $this->selectMock->expects($this->once())
            ->method('from')
            ->willReturn($this->selectMock);
        $this->connectionMock = $this->getMockBuilder(Adapter::class)
            ->getMockForAbstractClass();
        $this->connectionMock->expects($this->once())
            ->method('select')
            ->willReturn($this->selectMock);
        $this->connectionMock->expects($this->once())
            ->method('fetchOne')
            ->with($this->selectMock)
            ->willReturn(self::PARENT_PATH);
        $this->resourceMock = $this->getMockBuilder(ResourceConnection::class)
            ->disableOriginalConstructor()
            ->setMethods(['getConnection', 'getTableName'])
            ->getMock();
        $this->resourceMock->expects($this->any())
            ->method('getConnection')
            ->willReturn($this->connectionMock);
        $this->resourceMock->expects($this->any())
            ->method('getTableName')
            ->willReturn(self::TABLE_NAME);
        $this->contextMock = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->setMethods(['getResources'])
            ->getMock();
        $this->contextMock->expects($this->any())
            ->method('getResources')
            ->willReturn($this->resourceMock);

        $this->storeMock = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->setMethods(['getId'])
            ->getMock();
        $this->storeMock->expects($this->any())
            ->method('getId')
            ->willReturn(self::STORE_ID);
        $this->storeManagerMock = $this->getMockBuilder(StoreManagerInterface::class)
            ->getMockForAbstractClass();
        $this->storeManagerMock->expects($this->any())
            ->method('getStore')
            ->willReturn($this->storeMock);
    }

    public function testGetCategories()
    {
        $this->categoryCollectionFactoryMock = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->categoryCollectionMock = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->setMethods(
                [
                    'addNameToResult',
                    'addUrlRewriteToResult',
                    'addParentPathFilter',
                    'addStoreFilter',
                    'addIsActiveFilter',
                    'addAttributeToFilter',
                    'addSortedField',
                    'load'
                ]
            )
            ->getMock();
        $this->categoryCollectionMock->expects($this->once())
            ->method('addNameToResult')
            ->willReturn($this->categoryCollectionMock);
        $this->categoryCollectionMock->expects($this->once())
            ->method('addUrlRewriteToResult')
            ->willReturn($this->categoryCollectionMock);
        $this->categoryCollectionMock->expects($this->once())
            ->method('addParentPathFilter')
            ->with(self::PARENT_PATH)
            ->willReturn($this->categoryCollectionMock);
        $this->categoryCollectionMock->expects($this->once())
            ->method('addStoreFilter')
            ->willReturn($this->categoryCollectionMock);
        $this->categoryCollectionMock->expects($this->once())
            ->method('addIsActiveFilter')
            ->willReturn($this->categoryCollectionMock);
        $this->categoryCollectionMock->expects($this->once())
            ->method('addSortedField')
            ->with(self::SORTED)
            ->willReturn($this->categoryCollectionMock);
        $this->categoryCollectionMock->expects($this->once())
            ->method('addAttributeToFilter')
            ->with('include_in_menu', 1)
            ->willReturn($this->categoryCollectionMock);
        $this->categoryCollectionMock->expects($this->once())
            ->method('load')
            ->willReturn($this->categoryCollectionMock);
        $this->categoryCollectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->categoryCollectionMock);

        $this->model = $this->objectManager->getObject(
            Flat::class,
            [
                'context' => $this->contextMock,
                'storeManager' => $this->storeManagerMock,
            ]
        );

        $reflection = new \ReflectionClass(get_class($this->model));
        $reflectionProperty = $reflection->getProperty('categoryFlatCollectionFactory');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->model, $this->categoryCollectionFactoryMock);

        $this->assertEquals(
            $this->model->getCategories(self::PARENT, self::RECURSION_LEVEL, self::SORTED, true),
            $this->categoryCollectionMock
        );
    }
}
