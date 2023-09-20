<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\BundleImportExport\Test\Unit\Model\Import\Product\Type;

/**
 * Tests for Bundle
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class BundleTest extends \Magento\ImportExport\Test\Unit\Model\Import\AbstractImportTestCase
{
    /**
     * @var \Magento\BundleImportExport\Model\Import\Product\Type\Bundle
     */
    protected $bundle;

    /**
     * @var \Magento\Framework\App\ResourceConnection|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resource;

    /**
     * @var \Magento\Framework\DB\Select|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $select;

    /**
     * @var \Magento\CatalogImportExport\Model\Import\Product|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $entityModel;

    /**
     * @var []
     */
    protected $params;

    /** @var \Magento\Framework\DB\Adapter\AdapterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $connection;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $attrSetColFac;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $prodAttrColFac;

    /**
     * @var \Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\Collection|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $setCollection;

    /** @var \Magento\Framework\App\ScopeResolverInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $scopeResolver;

    /**
     *
     * @return void
     */
    protected function initFetchAllCalls()
    {
        $fetchAllForInitAttributes = [
            [
                'attribute_set_name' => '1',
                'attribute_id' => '1',
            ],
            [
                'attribute_set_name' => '2',
                'attribute_id' => '2',
            ],
        ];

        $fetchAllForOtherCalls = [[
            'selection_id' => '1',
            'option_id' => '1',
            'parent_product_id' => '1',
            'product_id' => '1',
            'position' => '1',
            'is_default' => '1'
        ]];

        $this->connection
            ->method('fetchAll')
            ->with($this->select)
            ->will($this->onConsecutiveCalls(
                $fetchAllForInitAttributes,
                $fetchAllForOtherCalls,
                $fetchAllForInitAttributes,
                $fetchAllForOtherCalls,
                $fetchAllForInitAttributes,
                $fetchAllForOtherCalls,
                $fetchAllForInitAttributes,
                $fetchAllForOtherCalls,
                $fetchAllForInitAttributes,
                $fetchAllForInitAttributes,
                $fetchAllForInitAttributes,
                $fetchAllForInitAttributes,
                $fetchAllForInitAttributes
            ));
    }

    /**
     * Set up
     *
     * @return void
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->entityModel = $this->createPartialMock(\Magento\CatalogImportExport\Model\Import\Product::class, [
                'getErrorAggregator',
                'getBehavior',
                'getNewSku',
                'getNextBunch',
                'isRowAllowedToImport',
                'getRowScope',
                'getConnection'
            ]);
        $this->entityModel->method('getErrorAggregator')->willReturn($this->getErrorAggregatorObject());
        $this->connection = $this->createPartialMock(
            \Magento\Framework\DB\Adapter\Pdo\Mysql::class,
            ['select', 'fetchAll', 'fetchPairs', 'joinLeft', 'insertOnDuplicate', 'delete', 'quoteInto', 'fetchAssoc']
        );
        $this->select = $this->createMock(\Magento\Framework\DB\Select::class);
        $this->select->expects($this->any())->method('from')->willReturnSelf();
        $this->select->expects($this->any())->method('where')->willReturnSelf();
        $this->select->expects($this->any())->method('joinLeft')->willReturnSelf();
        $this->select->expects($this->any())->method('getConnection')->willReturn($this->connection);
        $this->connection->expects($this->any())->method('select')->willReturn($this->select);
        $this->initFetchAllCalls();
        $this->connection->expects($this->any())->method('insertOnDuplicate')->willReturnSelf();
        $this->connection->expects($this->any())->method('delete')->willReturnSelf();
        $this->connection->expects($this->any())->method('quoteInto')->willReturn('');
        $this->resource = $this->createPartialMock(
            \Magento\Framework\App\ResourceConnection::class,
            ['getConnection', 'getTableName']
        );
        $this->resource->expects($this->any())->method('getConnection')->willReturn($this->connection);
        $this->resource->expects($this->any())->method('getTableName')->willReturn('tableName');
        $this->attrSetColFac = $this->createPartialMock(
            \Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory::class,
            ['create']
        );
        $this->setCollection = $this->createPartialMock(
            \Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\Collection::class,
            ['setEntityTypeFilter']
        );
        $this->attrSetColFac->expects($this->any())->method('create')->willReturn(
            $this->setCollection
        );
        $this->setCollection->expects($this->any())
            ->method('setEntityTypeFilter')
            ->willReturn([]);
        $this->prodAttrColFac = $this->createPartialMock(
            \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory::class,
            ['create']
        );
        $attrCollection =
            $this->createMock(\Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection::class);
        $attrCollection->expects($this->any())->method('addFieldToFilter')->willReturn([]);
        $this->prodAttrColFac->expects($this->any())->method('create')->willReturn($attrCollection);
        $this->params = [
            0 => $this->entityModel,
            1 => 'bundle'
        ];
        $this->scopeResolver = $this->getMockBuilder(\Magento\Framework\App\ScopeResolverInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getScope'])
            ->getMockForAbstractClass();
        $this->bundle = $this->objectManagerHelper->getObject(
            \Magento\BundleImportExport\Model\Import\Product\Type\Bundle::class,
            [
                'attrSetColFac' => $this->attrSetColFac,
                'prodAttrColFac' => $this->prodAttrColFac,
                'resource' => $this->resource,
                'params' => $this->params,
                'scopeResolver' => $this->scopeResolver,
            ]
        );

        $metadataMock = $this->createMock(\Magento\Framework\EntityManager\EntityMetadata::class);
        $metadataMock->expects($this->any())
            ->method('getLinkField')
            ->willReturn('entity_id');
        $metadataPoolMock = $this->createMock(\Magento\Framework\EntityManager\MetadataPool::class);
        $metadataPoolMock->expects($this->any())
            ->method('getMetadata')
            ->with(\Magento\Catalog\Api\Data\ProductInterface::class)
            ->willReturn($metadataMock);
        $reflection = new \ReflectionClass(\Magento\BundleImportExport\Model\Import\Product\Type\Bundle::class);
        $reflectionProperty = $reflection->getProperty('metadataPool');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->bundle, $metadataPoolMock);
    }

    /**
     * Test for method saveData()
     *
     * @param array $skus
     * @param array $bunch
     * @param $allowImport
     * @dataProvider saveDataProvider
     */
    public function testSaveData($skus, $bunch, $allowImport)
    {
        $this->entityModel->expects($this->any())->method('getBehavior')->willReturn(
            \Magento\ImportExport\Model\Import::BEHAVIOR_APPEND
        );
        $this->entityModel->expects($this->once())->method('getNewSku')->willReturn($skus['newSku']);
        $this->entityModel->expects($this->at(2))->method('getNextBunch')->willReturn([$bunch]);
        $this->entityModel->expects($this->any())->method('isRowAllowedToImport')->willReturn(
            $allowImport
        );
        $scope = $this->getMockBuilder(\Magento\Framework\App\ScopeInterface::class)->getMockForAbstractClass();
        $this->scopeResolver->expects($this->any())->method('getScope')->willReturn($scope);
        $this->connection->method('fetchPairs')->willReturn([1 => 'sku']);
        $this->connection->expects($this->any())->method('fetchAssoc')->with($this->select)->willReturn([
            '1' => [
                'option_id' => '1',
                'parent_id' => '1',
                'required' => '1',
                'position' => '1',
                'type' => 'bundle',
                'value_id' => '1',
                'title' => 'Bundle1',
                'name' => 'bundle1',
                'selections' => [
                    ['name' => 'Bundlen1',
                        'type' => 'dropdown',
                        'required' => '1',
                        'sku' => '1',
                        'price' => '10',
                        'price_type' => 'fixed',
                        'shipment_type' => '1',
                        'default_qty' => '1',
                        'is_default' => '1',
                        'position' => '1',
                        'option_id' => '1']
                ]
            ],
            '2' => [
                'option_id' => '6',
                'parent_id' => '6',
                'required' => '6',
                'position' => '6',
                'type' => 'bundle',
                'value_id' => '6',
                'title' => 'Bundle6',
                'selections' => [
                    ['name' => 'Bundlen6',
                        'type' => 'dropdown',
                        'required' => '1',
                        'sku' => '222',
                        'price' => '10',
                        'price_type' => 'percent',
                        'shipment_type' => 0,
                        'default_qty' => '2',
                        'is_default' => '1',
                        'position' => '6',
                        'option_id' => '6']
                ]
            ]
        ]);
        $bundle = $this->bundle->saveData();
        $this->assertNotNull($bundle);
    }

    /**
     * Data provider for saveData()
     *
     * @return array
     */
    public function saveDataProvider()
    {
        return [
            [
                'skus' => ['newSku' => ['sku' => ['sku' => 'sku', 'entity_id' => 3, 'type_id' => 'bundle']]],
                'bunch' => ['bundle_values' => 'value1', 'sku' => 'sku', 'name' => 'name'],
                'allowImport' => true
            ],
            [
                'skus' => ['newSku' => ['sku' => ['sku' => 'SKU', 'entity_id' => 3, 'type_id' => 'bundle']]],
                'bunch' => ['bundle_values' => 'value1', 'sku' => 'SKU', 'name' => 'name'],
                'allowImport' => true
            ],
            [
                'skus' => ['newSku' => ['sku' => ['sku' => 'sku', 'entity_id' => 3, 'type_id' => 'simple']]],
                'bunch' => ['bundle_values' => 'value1', 'sku' => 'sku', 'name' => 'name'],
                'allowImport' => true
            ],
            [
                'skus' => ['newSku' => ['sku' => ['sku' => 'sku', 'entity_id' => 3, 'type_id' => 'bundle']]],
                'bunch' => ['bundle_values' => 'value1', 'sku' => 'sku', 'name' => 'name'],
                'allowImport' => false
            ],
            'Import without bundle values' => [
                'skus' => ['newSku' => ['sku' => ['sku' => 'sku', 'entity_id' => 3, 'type_id' => 'bundle']]],
                'bunch' => ['sku' => 'sku', 'name' => 'name'],
                'allowImport' => true,
            ],
            [
                'skus' => ['newSku' => [
                    'sku' => ['sku' => 'sku', 'entity_id' => 3, 'type_id' => 'bundle'],
                    'sku1' => ['sku1' => 'sku1', 'entity_id' => 3, 'type_id' => 'bundle'],
                    'sku2' => ['sku2' => 'sku2', 'entity_id' => 3, 'type_id' => 'bundle']
                ]],
                'bunch' => [
                    'sku' => 'sku',
                    'name' => 'name',
                    'bundle_values' => 'name=Bundle1,'
                         . 'type=dropdown,'
                         . 'required=1,'
                         . 'sku=1,'
                         . 'price=10,'
                         . 'price_type=fixed,'
                         . 'shipment_type=separately,'
                         . 'default_qty=1,'
                         . 'is_default=1,'
                         . 'position=1,'
                         . 'option_id=1 | name=Bundle2,'
                         . 'type=dropdown,'
                         . 'required=1,'
                         . 'sku=2,'
                         . 'price=10,'
                         . 'price_type=fixed,'
                         . 'default_qty=1,'
                         . 'is_default=1,'
                         . 'position=2,'
                         . 'option_id=2'
                ],
                'allowImport' => true
            ]
        ];
    }

    /**
     * Test for method saveData()
     */
    public function testSaveDataDelete()
    {
        $this->entityModel->expects($this->any())->method('getBehavior')->willReturn(
            \Magento\ImportExport\Model\Import::BEHAVIOR_DELETE
        );
        $this->entityModel->expects($this->once())->method('getNewSku')->willReturn([
            'sku' => ['sku' => 'sku', 'entity_id' => 3, 'attr_set_code' => 'Default', 'type_id' => 'bundle']
        ]);
        $this->entityModel->expects($this->at(2))->method('getNextBunch')->willReturn([
            ['bundle_values' => 'value1', 'sku' => 'sku', 'name' => 'name']
        ]);
        $this->entityModel->expects($this->any())->method('isRowAllowedToImport')->willReturn(true);
        $select = $this->createMock(\Magento\Framework\DB\Select::class);
        $this->connection->expects($this->any())->method('select')->willReturn($select);
        $select->expects($this->any())->method('from')->willReturnSelf();
        $select->expects($this->any())->method('where')->willReturnSelf();
        $select->expects($this->any())->method('joinLeft')->willReturnSelf();
        $this->connection->expects($this->any())->method('fetchAssoc')->with($select)->willReturn([
            ['id1', 'id2', 'id_3']
        ]);
        $bundle = $this->bundle->saveData();
        $this->assertNotNull($bundle);
    }

    public function testPrepareAttributesWithDefaultValueForSaveInsideCall()
    {
        $bundleMock = $this->createPartialMock(
            \Magento\BundleImportExport\Model\Import\Product\Type\Bundle::class,
            ['transformBundleCustomAttributes']
        );
        // Set some attributes to bypass errors due to static call inside method.
        $attrVal = 'value';
        $rowData = [
            \Magento\CatalogImportExport\Model\Import\Product::COL_ATTR_SET => $attrVal,
        ];
        $this->setPropertyValue($bundleMock, '_attributes', [
            $attrVal => [],
        ]);

        $bundleMock
            ->expects($this->once())
            ->method('transformBundleCustomAttributes')
            ->with($rowData)
            ->willReturn([]);

        $bundleMock->prepareAttributesWithDefaultValueForSave($rowData);
    }

    /**
     * Test for isRowValid()
     */
    public function testIsRowValid()
    {
        $this->entityModel->expects($this->any())->method('getRowScope')->willReturn(-1);
        $rowData = [
            'bundle_price_type' => 'dynamic',
            'bundle_shipment_type' => 'separately',
            'bundle_price_view' => 'bundle_price_view'
        ];
        $this->assertEquals($this->bundle->isRowValid($rowData, 0), true);
    }

    /**
     * @param $object
     * @param $property
     * @param $value
     */
    protected function setPropertyValue(&$object, $property, $value)
    {
        $reflection = new \ReflectionClass(get_class($object));
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($object, $value);
        return $object;
    }
}
