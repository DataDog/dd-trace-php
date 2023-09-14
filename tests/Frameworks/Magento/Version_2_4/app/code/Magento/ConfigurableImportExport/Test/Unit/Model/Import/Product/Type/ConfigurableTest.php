<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableImportExport\Test\Unit\Model\Import\Product\Type;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ProductTypes\ConfigInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogImportExport\Model\Import\Product;
use Magento\ConfigurableImportExport;
use Magento\ConfigurableImportExport\Model\Import\Product\Type\Configurable;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\Collection;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadata;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\ImportExport\Test\Unit\Model\Import\AbstractImportTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;

/**
 * Configurable import export tests
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ConfigurableTest extends AbstractImportTestCase
{
    /** @var ConfigurableImportExport\Model\Import\Product\Type\Configurable */
    protected $configurable;

    /**
     * @var MockObject
     */
    protected $setCollectionFactory;

    /**
     * @var Collection|MockObject
     */
    protected $setCollection;

    /**
     * @var MockObject
     */
    protected $attrCollectionFactory;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection|MockObject
     */
    protected $attrCollection;

    /**
     * @var CollectionFactory|MockObject
     */
    protected $productCollectionFactory;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\Collection|MockObject
     */
    protected $productCollection;

    /**
     * @var ConfigInterface|MockObject
     */
    protected $productTypesConfig;

    /**
     * @var []
     */
    protected $params;

    /**
     * @var \Magento\CatalogImportExport\Model\Import\Product|MockObject
     */
    protected $_entityModel;

    /** @var ResourceConnection|MockObject */
    protected $resource;

    /** @var Mysql|MockObject */
    protected $_connection;

    /** @var Select|MockObject */
    protected $select;

    /**
     * @var string
     */
    protected $productEntityLinkField = 'entity_id';

    /**
     * @inheritdoc
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setCollectionFactory = $this->createPartialMock(
            \Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory::class,
            ['create']
        );
        $this->setCollection = $this->createPartialMock(
            Collection::class,
            ['setEntityTypeFilter']
        );

        $this->setCollectionFactory->expects($this->any())->method('create')->willReturn(
            $this->setCollection
        );

        $item = new DataObject(
            [
            'id' => 1,
            'attribute_set_name' => 'Default',
            '_attribute_set' => 'Default'
            ]
        );

        $this->setCollection->expects($this->any())
            ->method('setEntityTypeFilter')
            ->willReturn([$item]);

        $this->attrCollectionFactory = $this->createPartialMock(
            \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory::class,
            ['create']
        );

        $this->attrCollection = $this->createPartialMock(
            \Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection::class,
            ['setAttributeSetFilter']
        );

        $superAttributes = [];
        foreach ($this->_getSuperAttributes() as $superAttribute) {
            $item = $this->getMockBuilder(AbstractAttribute::class)
                ->onlyMethods(['isStatic'])
                ->disableOriginalConstructor()
                ->setConstructorArgs($superAttribute)
                ->getMock();
            $item->setData($superAttribute);
            $item->method('isStatic')
                ->willReturn(false);
            $superAttributes[] = $item;
        }

        $this->attrCollectionFactory->expects($this->any())->method('create')->willReturn(
            $this->attrCollection
        );

        $this->attrCollection->expects($this->any())
            ->method('setAttributeSetFilter')
            ->willReturn($superAttributes);

        $this->_entityModel = $this->createPartialMock(
            Product::class,
            [
                'getNewSku',
                'getOldSku',
                'getNextBunch',
                'isRowAllowedToImport',
                'getConnection',
                'getAttrSetIdToName',
                'getErrorAggregator',
                'getAttributeOptions'
            ]
        );
        $this->_entityModel->method('getErrorAggregator')->willReturn($this->getErrorAggregatorObject());

        $this->params = [
            0 => $this->_entityModel,
            1 => 'configurable'
        ];

        $this->_connection = $this->getMockBuilder(Mysql::class)
            ->addMethods(['joinLeft'])
            ->onlyMethods(
                [
                    'select',
                    'fetchAll',
                    'fetchPairs',
                    'insertOnDuplicate',
                    'quoteIdentifier',
                    'delete',
                    'quoteInto'
                ]
            )
            ->disableOriginalConstructor()
            ->getMock();
        $this->select = $this->createPartialMock(
            Select::class,
            [
                'from',
                'where',
                'joinLeft',
                'getConnection'
            ]
        );
        $this->select->expects($this->any())->method('from')->willReturnSelf();
        $this->select->expects($this->any())->method('where')->willReturnSelf();
        $this->select->expects($this->any())->method('joinLeft')->willReturnSelf();
        $this->_connection->expects($this->any())->method('select')->willReturn($this->select);
        $connectionMock = $this->createMock(Mysql::class);
        $connectionMock->expects($this->any())->method('quoteInto')->willReturn('query');
        $this->select->expects($this->any())->method('getConnection')->willReturn($connectionMock);
        $this->_connection->expects($this->any())->method('insertOnDuplicate')->willReturnSelf();
        $this->_connection->expects($this->any())->method('delete')->willReturnSelf();
        $this->_connection->expects($this->any())->method('quoteInto')->willReturn('');
        $this->_connection->expects($this->any())->method('fetchAll')->willReturn([]);

        $this->resource = $this->createPartialMock(
            ResourceConnection::class,
            [
                'getConnection',
                'getTableName'
                ]
        );
        $this->resource->expects($this->any())->method('getConnection')->willReturn(
            $this->_connection
        );
        $this->resource->expects($this->any())->method('getTableName')->willReturn(
            'tableName'
        );
        $this->_entityModel->expects($this->any())->method('getConnection')->willReturn(
            $this->_connection
        );

        $this->productCollectionFactory = $this->createPartialMock(
            CollectionFactory::class,
            ['create']
        );

        $this->productCollection = $this->createPartialMock(
            \Magento\Catalog\Model\ResourceModel\Product\Collection::class,
            ['addFieldToFilter', 'addAttributeToSelect']
        );

        $products = [];
        $testProducts = [
            ['id' => 1, 'attribute_set_id' => 4, 'testattr2'=> 1, 'testattr3'=> 1],
            ['id' => 2, 'attribute_set_id' => 4, 'testattr2'=> 1, 'testattr3'=> 1],
            ['id' => 20, 'attribute_set_id' => 4, 'testattr2'=> 1, 'testattr3'=> 1]
        ];
        foreach ($testProducts as $product) {
            $item = $this->getMockBuilder(DataObject::class)
                ->addMethods(['getAttributeSetId'])
                ->disableOriginalConstructor()
                ->getMock();
            $item->setData($product);
            $item->expects($this->any())->method('getAttributeSetId')->willReturn(4);

            $products[] = $item;
        }

        $this->productCollectionFactory->expects($this->any())->method('create')->willReturn(
            $this->productCollection
        );

        $this->productCollection->expects($this->any())->method('addFieldToFilter')->willReturn(
            $this->productCollection
        );

        $this->productCollection->expects($this->any())->method('addAttributeToSelect')->willReturn(
            $products
        );

        $this->_entityModel->expects($this->any())->method('getAttributeOptions')->willReturn([
            'attr2val1' => '1',
            'attr2val2' => '2',
            'attr2val3' => '3',
            'testattr3v1' => '4',
            'testattr30v1' => '4',
            'testattr3v2' => '5',
            'testattr3v3' => '6'
        ]);

        $metadataPoolMock = $this->createMock(MetadataPool::class);
        $entityMetadataMock = $this->createMock(EntityMetadata::class);
        $metadataPoolMock->expects($this->any())
            ->method('getMetadata')
            ->with(ProductInterface::class)
            ->willReturn($entityMetadataMock);
        $entityMetadataMock->expects($this->any())
            ->method('getLinkField')
            ->willReturn($this->productEntityLinkField);
        $entityMetadataMock->expects($this->any())
            ->method('getIdentifierField')
            ->willReturn($this->productEntityLinkField);

        $this->configurable = $this->objectManagerHelper->getObject(
            Configurable::class,
            [
                'attrSetColFac' => $this->setCollectionFactory,
                'prodAttrColFac' => $this->attrCollectionFactory,
                'params' => $this->params,
                'resource' => $this->resource,
                'productColFac' => $this->productCollectionFactory,
                'metadataPool' => $metadataPoolMock
            ]
        );
    }

    /**
     * Bunches data provider
     *
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function _getBunch(): array
    {
        return [
            [
                'sku' => 'configurableskuI22',
                'store_view_code' => null,
                'attribute_set_code' => 'Default',
                'product_type' => 'configurable',
                'name' => 'Configurable Product 21',
                'product_websites' => 'website_1',
                'configurable_variation_labels' => 'testattr2=Select Color, testattr3=Select Size',
                'configurable_variations' => 'sku=testconf2-attr2val1-testattr3v1,'
                    . 'testattr2=attr2val1,'
                    . 'testattr3=testattr3v1,'
                    . 'display=1|sku=testconf2-attr2val1-testattr3v2,'
                    . 'testattr2=attr2val1,'
                    . 'testattr3=testattr3v2,'
                    . 'display=0',
                '_store' => null,
                '_attribute_set' => 'Default',
                '_type' => 'configurable',
                '_product_websites' => 'website_1'
            ],
            [
                'sku' => 'testSimple',
                'store_view_code' => null,
                'attribute_set_code' => 'Default',
                'product_type' => 'simple',
                'name' => 'Test simple',
                'product_websites' => 'website_1',
                '_store' => null,
                '_attribute_set' => 'Default',
                '_type' => 'simple',
                '_product_websites' => 'website_1'
            ],
            [
                'sku' => 'testSimpleToSkip',
                'store_view_code' => null,
                'attribute_set_code' => 'Default',
                'product_type' => 'simple',
                'name' => 'Test simple to Skip',
                'product_websites' => 'website_1',
                '_store' => null,
                '_attribute_set' => 'Default',
                '_type' => 'simple',
                '_product_websites' => 'website_1'
            ],
            [
                'sku' => 'configurableskuI22withoutLabels',
                'store_view_code' => null,
                'attribute_set_code' => 'Default',
                'product_type' => 'configurable',
                'name' => 'Configurable Product 21 Without Labels',
                'product_websites' => 'website_1',
                'configurable_variations' => '
                sku=testconf2-attr2val1-testattr3v1,testattr2=attr2val1,testattr3=testattr3v1,display=1|
                sku=testconf2-attr2val1-testattr30v1,testattr2=attr2val1,testattr3=testattr3v1,display=1|
                sku=testconf2-attr2val1-testattr3v2,testattr2=attr2val1,testattr3=testattr3v2,display=0|
                sku=testconf2-attr2val2-testattr3v2,testattr2=attr2val1,testattr4=testattr3v2,display=1|
                sku=testSimpleOld,testattr2=attr2val1,testattr4=testattr3v2,display=1',
                '_store' => null,
                '_attribute_set' => 'Default',
                '_type' => 'configurable',
                '_product_websites' => 'website_1'
            ],
            [
                'sku' => 'configurableskuI22withoutVariations',
                'store_view_code' => null,
                'attribute_set_code' => 'Default',
                'product_type' => 'configurable',
                'name' => 'Configurable Product 21 Without Labels',
                'product_websites' => 'website_1',
                '_store' => null,
                '_attribute_set' => 'Default',
                '_type' => 'configurable',
                '_product_websites' => 'website_1'
            ],
            [
                'sku' => 'configurableskuI22Duplicated',
                'store_view_code' => null,
                'attribute_set_code' => 'Default',
                'product_type' => 'configurable',
                'name' => 'Configurable Product 21',
                'product_websites' => 'website_1',
                'configurable_variation_labels' => 'testattr2=Select Color, testattr3=Select Size',
                'configurable_variations' => 'sku=testconf2-attr2val1-testattr3v1,'
                    . 'testattr2=attr2val1,'
                    . 'testattr3=testattr3v1,'
                    . 'testattr3=testattr3v2,'
                    . 'display=1|'
                    . 'sku=testconf2-attr2val1-testattr3v2,'
                    . 'testattr2=attr2val1,'
                    . 'testattr3=testattr3v1,'
                    . 'testattr3=testattr3v2,'
                    . 'display=1|'
                    . 'sku=testconf2-attr2val1-testattr3v3,'
                    . 'testattr2=attr2val1,'
                    . 'testattr3=testattr3v1,'
                    . 'testattr3=testattr3v2,'
                    . 'display=1',
                '_store' => null,
                '_attribute_set' => 'Default',
                '_type' => 'configurable',
                '_product_websites' => 'website_1'
            ],
            [
                'sku' => 'testSimpleOld',
                'store_view_code' => null,
                'attribute_set_code' => 'Default',
                'product_type' => 'simple',
                'name' => 'Test simple to Skip',
                'product_websites' => 'website_1',
                '_store' => null,
                '_attribute_set' => 'Default',
                '_type' => 'simple',
                '_product_websites' => 'website_1'
            ]
        ];
    }

    /**
     * Super attributes data provider
     *
     * @return array
     */
    protected function _getSuperAttributes(): array
    {
        return [
            'testattr2' => [
                'id' => '131',
                'code' => 'testattr2',
                'attribute_code' => 'testattr2',
                'is_global' => '1',
                'is_visible' => '1',
                'is_required' => '0',
                'is_unique' => '0',
                'frontend_label' => 'testattr2',
                'is_static' => false,
                'backend_type' => 'select',
                'apply_to' => [],
                'type' => 'select',
                'default_value' => null,
                'options' => [
                    'attr2val1' => '6',
                    'attr2val2' => '7',
                    'attr2val3' => '8'
                ]
            ],
            'testattr3' => [
                'id' => '132',
                'code' => 'testattr3',
                'attribute_code' => 'testattr3',
                'is_global' => '1',
                'is_visible' => '1',
                'is_required' => '0',
                'is_unique' => '0',
                'frontend_label' => 'testattr3',
                'is_static' => false,
                'backend_type' => 'select',
                'apply_to' => [],
                'type' => 'select',
                'default_value' => null,
                'options' => [
                    'testattr3v1' => '9',
                    'testattr3v2' => '10',
                    'testattr3v3' => '11'
                ]
            ]
        ];
    }

    /**
     * Verify save mtethod
     *
     * @return void
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testSaveData(): void
    {
        $newSkus = array_change_key_case([
            'configurableskuI22' => [
                $this->productEntityLinkField => 1,
                'type_id' => 'configurable',
                'attr_set_code' => 'Default'
            ],
            'testconf2-attr2val1-testattr3v1' => [
                $this->productEntityLinkField => 2,
                'type_id' => 'simple',
                'attr_set_code' => 'Default'
            ],
            'testconf2-attr2val1-testattr30v1' => [
                $this->productEntityLinkField => 20,
                'type_id' => 'simple',
                'attr_set_code' => 'Default'
            ],
            'testconf2-attr2val1-testattr3v2' => [
                $this->productEntityLinkField => 3,
                'type_id' => 'simple',
                'attr_set_code' => 'Default'
            ],
            'testSimple' => [
                $this->productEntityLinkField => 4,
                'type_id' => 'simple', 'attr_set_code' => 'Default'
            ],
            'testSimpleToSkip' => [
                $this->productEntityLinkField => 5,
                'type_id' => 'simple',
                'attr_set_code' => 'Default'
            ],
            'configurableskuI22withoutLabels' => [
                $this->productEntityLinkField => 6,
                'type_id' => 'configurable',
                'attr_set_code' => 'Default'
            ],
            'configurableskuI22withoutVariations' => [
                $this->productEntityLinkField => 7,
                'type_id' => 'configurable',
                'attr_set_code' => 'Default'
            ],
            'configurableskuI22Duplicated' => [
                $this->productEntityLinkField => 8,
                'type_id' => 'configurable',
                'attr_set_code' => 'Default'
            ],
            'configurableskuI22BadPrice' => [
                $this->productEntityLinkField => 9,
                'type_id' => 'configurable',
                'attr_set_code' => 'Default'
            ]
        ]);
        $this->_entityModel->expects($this->any())
            ->method('getNewSku')
            ->willReturn($newSkus);

        // at(0) is select() call, quoteIdentifier() is invoked at(1) and at(2)
        $this->_connection
            ->method('quoteIdentifier')
            ->withConsecutive(['m.attribute_id'], ['o.attribute_id'])
            ->willReturnOnConsecutiveCalls('a', 'b');

        $this->_connection->expects($this->any())->method('select')->willReturn($this->select);
        $this->_connection->expects($this->any())->method('fetchAll')->with($this->select)->willReturn(
            [
                ['attribute_id' => 131, 'product_id' => 1, 'option_id' => 1, 'product_super_attribute_id' => 131],

                ['attribute_id' => 131, 'product_id' => 2, 'option_id' => 1, 'product_super_attribute_id' => 131],
                ['attribute_id' => 131, 'product_id' => 2, 'option_id' => 2, 'product_super_attribute_id' => 131],
                ['attribute_id' => 131, 'product_id' => 2, 'option_id' => 3, 'product_super_attribute_id' => 131],

                ['attribute_id' => 131, 'product_id' => 20, 'option_id' => 1, 'product_super_attribute_id' => 131],
                ['attribute_id' => 131, 'product_id' => 20, 'option_id' => 2, 'product_super_attribute_id' => 131],
                ['attribute_id' => 131, 'product_id' => 20, 'option_id' => 3, 'product_super_attribute_id' => 131],

                ['attribute_id' => 132, 'product_id' => 1, 'option_id' => 1, 'product_super_attribute_id' => 132],
                ['attribute_id' => 132, 'product_id' => 1, 'option_id' => 2, 'product_super_attribute_id' => 132],
                ['attribute_id' => 132, 'product_id' => 1, 'option_id' => 3, 'product_super_attribute_id' => 132],
                ['attribute_id' => 132, 'product_id' => 1, 'option_id' => 4, 'product_super_attribute_id' => 132],
                ['attribute_id' => 132, 'product_id' => 1, 'option_id' => 5, 'product_super_attribute_id' => 132],
                ['attribute_id' => 132, 'product_id' => 1, 'option_id' => 6, 'product_super_attribute_id' => 132],

                ['attribute_id' => 132, 'product_id' => 3, 'option_id' => 3, 'product_super_attribute_id' => 132],
                ['attribute_id' => 132, 'product_id' => 4, 'option_id' => 4, 'product_super_attribute_id' => 132],
                ['attribute_id' => 132, 'product_id' => 5, 'option_id' => 5, 'product_super_attribute_id' => 132]
            ]
        );
        $this->_connection->expects($this->any())->method('fetchAll')->with($this->select)->willReturn([]);

        $bunch = $this->_getBunch();
        $this->_entityModel
            ->method('getNextBunch')
            ->willReturnOnConsecutiveCalls($bunch, []);
        $this->_entityModel->expects($this->any())
            ->method('isRowAllowedToImport')
            ->willReturnCallback([$this, 'isRowAllowedToImport']);

        $this->_entityModel->expects($this->any())->method('getOldSku')->willReturn([
            'testsimpleold' => [
                $this->productEntityLinkField => 10,
                'type_id' => 'simple',
                'attr_set_code' => 'Default'
            ],
        ]);

        $this->_entityModel->expects($this->any())->method('getAttrSetIdToName')->willReturn([4 => 'Default']);

        $this->configurable->saveData();
    }

    /**
     * Callback for is row allowed to import
     *
     * @param $rowData
     * @param $rowNum
     *
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function isRowAllowedToImport($rowData, $rowNum): bool
    {
        if ($rowNum == 2) {
            return false;
        }
        return true;
    }

    /**
     * Verify is row valid method
     *
     * @dataProvider getProductDataIsValidRow
     * @param array $productData
     *
     * @return void
     */
    public function testIsRowValid(array $productData): void
    {
        $bunch = $this->_getBunch();
        // Checking that variations' field names are case-insensitive with this
        // product.
        $caseInsensitiveSKU = 'configurableskuI22CaseInsensitive';
        $productData['caseInsencitiveProduct']['sku'] = $caseInsensitiveSKU;
        $bunch[] = $productData['bad_product'];
        $bunch[] = $productData['caseInsencitiveProduct'];
        // Set _attributes to avoid error in Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType.
        $this->setPropertyValue($this->configurable, '_attributes', [
            $productData['bad_product'][Product::COL_ATTR_SET] => [],
        ]);
        // Avoiding errors about attributes not being super
        $this->setPropertyValue($this->configurable, '_superAttributes', $productData['super_attributes']);

        foreach ($bunch as $rowData) {
            $result = $this->configurable->isRowValid($rowData, 0, false);
            $this->assertNotNull($result);
            if ($rowData['sku'] === $caseInsensitiveSKU) {
                $this->assertTrue($result);
            }
        }
    }

    /**
     *
     * Data provider for isValidRows test.
     *
     * @return array
     */
    public function getProductDataIsValidRow(): array
    {
        return [
            [
                [
                    'bad_product' => [
                        'sku' => 'configurableskuI22BadPrice',
                        'store_view_code' => null,
                        'attribute_set_code' => 'Default',
                        'product_type' => 'configurable',
                        'name' => 'Configurable Product 21 BadPrice',
                        'product_websites' => 'website_1',
                        'configurable_variation_labels' => 'testattr2=Select Color, testattr3=Select Size',
                        'configurable_variations' => 'sku=testconf2-attr2val1-testattr3v1,'
                            . 'testattr2=attr2val1_DOESNT_EXIST,'
                            . 'testattr3=testattr3v1,'
                            . 'display=1|sku=testconf2-attr2val1-testattr3v2,'
                            . 'testattr2=attr2val1,'
                            . 'testattr3=testattr3v2,'
                            . 'display=0',
                        '_store' => null,
                        '_attribute_set' => 'Default',
                        '_type' => 'configurable',
                        '_product_websites' => 'website_1',
                    ],
                    'caseInsencitiveProduct' => [
                        'sku' => '',
                        'store_view_code' => null,
                        'attribute_set_code' => 'Default',
                        'product_type' => 'configurable',
                        'name' => 'Configurable Product 21',
                        'product_websites' => 'website_1',
                        'configurable_variation_labels' => 'testattr2=Select Color, testattr3=Select Size',
                        'configurable_variations' => 'SKU=testconf2-attr2val1-testattr3v1,'
                            . 'testattr2=attr2val1,'
                            . 'testattr3=testattr3v1=sx=sl,'
                            . 'display=1|sku=testconf2-attr2val1-testattr3v2,'
                            . 'testattr2=attr2val1,'
                            . 'testattr3=testattr3v2,'
                            . 'display=0',
                        '_store' => null,
                        '_attribute_set' => 'Default',
                        '_type' => 'configurable',
                        '_product_websites' => 'website_1'
                    ],
                    'super_attributes' => [
                        'testattr2' => ['options' => ['attr2val1' => 1]],
                        'testattr3' => [
                            'options' => [
                                'testattr3v2' => 1,
                                'testattr3v1=sx=sl' => 1
                            ],
                        ],
                    ]
                ]
            ]
        ];
    }

    /**
     * Verify row validation with numeric skus
     *
     * @return void
     */
    public function testRowValidationForNumericalSkus(): void
    {
        // Set _attributes to avoid error in Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType.
        $this->setPropertyValue($this->configurable, '_attributes', [
            'Default' => [],
        ]);
        // Avoiding errors about attributes not being super
        $this->setPropertyValue(
            $this->configurable,
            '_superAttributes',
            [
                'testattr2' => [
                    'options' => [
                        'attr2val1' => 1,
                        'attr2val2' => 2
                    ]
                ],
            ]
        );

        $rowValidationDataProvider = $this->rowValidationDataProvider();

        // Checking that variations with duplicate sku are invalid
        $result = $this->configurable->isRowValid($rowValidationDataProvider['duplicateProduct'], 0);
        $this->assertFalse($result);

        // Checking that variations with SKUs that are the same when interpreted as number,
        // but different when interpreted as string are valid
        $result = $this->configurable->isRowValid($rowValidationDataProvider['nonDuplicateProduct'], 0);
        $this->assertTrue($result);
    }

    /**
     * Row validation Data Provider
     *
     * @return array
     */
    public function rowValidationDataProvider(): array
    {
        return [
            'duplicateProduct' => [
                'sku' => 'configurableNumericalSkuDuplicateVariation',
                'store_view_code' => null,
                'attribute_set_code' => 'Default',
                'product_type' => 'configurable',
                'name' => 'Configurable Product with duplicate numerical SKUs in variations',
                'product_websites' => 'website_1',
                'configurable_variation_labels' => 'testattr2=Select Configuration',
                'configurable_variations' => 'sku=1234.1,'
                    . 'testattr2=attr2val1,'
                    . 'display=1|sku=1234.1,'
                    . 'testattr2=attr2val1,'
                    . 'display=0',
                '_store' => null,
                '_attribute_set' => 'Default',
                '_type' => 'configurable',
                '_product_websites' => 'website_1'
            ],
            'nonDuplicateProduct' => [
                'sku' => 'configurableNumericalSkuNonDuplicateVariation',
                'store_view_code' => null,
                'attribute_set_code' => 'Default',
                'product_type' => 'configurable',
                'name' => 'Configurable Product with different numerical SKUs in variations',
                'product_websites' => 'website_1',
                'configurable_variation_labels' => 'testattr2=Select Configuration',
                'configurable_variations' => 'sku=1234.10,'
                    . 'testattr2=attr2val1,'
                    . 'display=1|sku=1234.1,'
                    . 'testattr2=attr2val2,'
                    . 'display=0',
                '_store' => null,
                '_attribute_set' => 'Default',
                '_type' => 'configurable',
                '_product_websites' => 'website_1'
            ]
        ];
    }

    /**
     * Set object property value.
     *
     * @param $object
     * @param $property
     * @param $value
     */
    protected function setPropertyValue(&$object, $property, $value)
    {
        $reflection = new ReflectionClass(get_class($object));
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($object, $value);

        return $object;
    }
}
