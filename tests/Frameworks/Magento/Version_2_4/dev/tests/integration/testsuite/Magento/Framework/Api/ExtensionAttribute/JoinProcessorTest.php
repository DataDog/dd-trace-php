<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Api\ExtensionAttribute;

use Magento\Framework\Api\ExtensionAttribute\Config\Converter;
use Magento\Framework\Api\ExtensionAttribute\Config\Reader;
use Magento\Framework\Api\ExtensionAttribute\JoinData;
use Magento\Framework\Api\ExtensionAttribute\JoinDataInterfaceFactory;
use Magento\Framework\Reflection\TypeProcessor;
use Magento\Framework\App\ResourceConnection as AppResource;
use Magento\Framework\Api\ExtensionAttributesFactory;

/**
 * Class to test the JoinProcessor functionality
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class JoinProcessorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Api\ExtensionAttribute\JoinProcessor
     */
    private $joinProcessor;

    /**
     * @var Reader|\PHPUnit\Framework\MockObject\MockObject
     */
    private $config;

    /**
     * @var JoinDataInterfaceFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $extensionAttributeJoinDataFactory;

    /**
     * @var TypeProcessor|\PHPUnit\Framework\MockObject\MockObject
     */
    private $typeProcessor;

    /**
     * @var AppResource|\PHPUnit\Framework\MockObject\MockObject
     */
    private $appResource;

    /**
     * @var ExtensionAttributesFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $extensionAttributesFactory;

    /**
     * @var \Magento\Framework\Api\ExtensionAttribute\JoinProcessorHelper
     */
    private $joinProcessorHelper;

    protected function setUp(): void
    {
        $this->config = $this->getMockBuilder(\Magento\Framework\Api\ExtensionAttribute\Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->extensionAttributeJoinDataFactory = $this
            ->getMockBuilder(\Magento\Framework\Api\ExtensionAttribute\JoinDataInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->typeProcessor = $this->getMockBuilder(\Magento\Framework\Reflection\TypeProcessor::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->extensionAttributesFactory = $this->getMockBuilder(
            \Magento\Framework\Api\ExtensionAttributesFactory::class
        )->disableOriginalConstructor()
            ->getMock();

        /** @var \Magento\Framework\ObjectManagerInterface */
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();

        $this->appResource = $objectManager->get(\Magento\Framework\App\ResourceConnection::class);

        $this->joinProcessorHelper = $objectManager->create(
            \Magento\Framework\Api\ExtensionAttribute\JoinProcessorHelper::class,
            [
                'config' => $this->config,
                'joinDataInterfaceFactory' => $this->extensionAttributeJoinDataFactory
            ]
        );

        $this->joinProcessor = $objectManager->create(
            \Magento\Framework\Api\ExtensionAttribute\JoinProcessor::class,
            [
                'objectManager' => $objectManager,
                'typeProcessor' => $this->typeProcessor,
                'joinProcessorHelper' => $this->joinProcessorHelper
            ]
        );
    }

    /**
     * Test the processing of the join config for a particular type
     */
    public function testProcess()
    {
        $this->config->expects($this->once())
            ->method('get')
            ->willReturn($this->getConfig());

        $collection = $this->getMockBuilder(\Magento\Framework\Data\Collection\AbstractDb::class)
            ->disableOriginalConstructor()
            ->setMethods(['joinExtensionAttribute'])
            ->getMockForAbstractClass();

        $extensionAttributeJoinData = new JoinData();
        $this->extensionAttributeJoinDataFactory
            ->expects($this->once())
            ->method('create')
            ->willReturn($extensionAttributeJoinData);

        $collection->expects($this->once())->method('joinExtensionAttribute')->with($extensionAttributeJoinData);

        $this->joinProcessor->process($collection, \Magento\Catalog\Api\Data\ProductInterface::class);
        $expectedTableName = 'reviews';
        $this->assertEquals($expectedTableName, $extensionAttributeJoinData->getReferenceTable());
        $this->assertEquals('extension_attribute_review_id', $extensionAttributeJoinData->getReferenceTableAlias());
        $this->assertEquals('product_id', $extensionAttributeJoinData->getReferenceField());
        $this->assertEquals('id', $extensionAttributeJoinData->getJoinField());
        $this->assertEquals(
            [
                [
                    'external_alias' => 'review_id',
                    'internal_alias' => 'extension_attribute_review_id_db_review_id',
                    'with_db_prefix' => 'extension_attribute_review_id.db_review_id',
                    'setter' => 'setReviewId',
                ]
            ],
            $extensionAttributeJoinData->getSelectFields()
        );
    }

    /**
     * Will return the data that is expected from the config object
     *
     * @return array
     */
    private function getConfig()
    {
        return [\Magento\Catalog\Api\Data\ProductInterface::class => [
                'review_id' => [
                    Converter::DATA_TYPE => 'string',
                    Converter::RESOURCE_PERMISSIONS => [],
                    Converter::JOIN_DIRECTIVE => [
                        Converter::JOIN_REFERENCE_TABLE => "reviews",
                        Converter::JOIN_REFERENCE_FIELD => "product_id",
                        Converter::JOIN_FIELDS => [
                            [
                                Converter::JOIN_FIELD => "review_id",
                                Converter::JOIN_FIELD_COLUMN => "db_review_id",
                            ],
                        ],
                        Converter::JOIN_ON_FIELD => "id",
                    ],
                ],
            ], \Magento\Customer\Api\Data\CustomerInterface::class => [
                'library_card_id' => [
                    Converter::DATA_TYPE => 'string',
                    Converter::RESOURCE_PERMISSIONS => [],
                    Converter::JOIN_DIRECTIVE => [
                        Converter::JOIN_REFERENCE_TABLE => "library_account",
                        Converter::JOIN_FIELDS => [
                            [
                                Converter::JOIN_FIELD => "library_card_id",
                                Converter::JOIN_FIELD_COLUMN => "",
                            ],
                        ],
                        Converter::JOIN_ON_FIELD => "customer_id",
                    ],
                ],
                'reviews' => [
                    Converter::DATA_TYPE => 'Magento\Reviews\Api\Data\Reviews[]',
                    Converter::RESOURCE_PERMISSIONS => [],
                    Converter::JOIN_DIRECTIVE => [
                        Converter::JOIN_REFERENCE_TABLE => "reviews",
                        Converter::JOIN_FIELDS => [
                            [
                                Converter::JOIN_FIELD => "comment",
                                Converter::JOIN_FIELD_COLUMN => "",
                            ],
                            [
                                Converter::JOIN_FIELD => "rating",
                                Converter::JOIN_FIELD_COLUMN => "",
                            ],
                        ],
                        Converter::JOIN_ON_FIELD => "customer_id",
                    ],
                ],
            ],
        ];
    }

    public function testProcessSqlSelectVerification()
    {
        /** @var \Magento\Framework\ObjectManagerInterface */
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        /** @var \Magento\Framework\Api\ExtensionAttribute\Config $config */
        $config = $objectManager->get(\Magento\Framework\Api\ExtensionAttribute\Config::class);
        $config->reset();

        $extensionConfigFileResolverMock = $this->getMockBuilder(\Magento\Framework\Config\FileResolverInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $extensionConfigFilePath = __DIR__ . '/../_files/extension_attributes.xml';
        $extensionConfigFileContent = file_get_contents($extensionConfigFilePath);
        $extensionConfigFileResolverMock->expects($this->any())
            ->method('get')
            ->willReturn([$extensionConfigFilePath => $extensionConfigFileContent]);
        $configReader = $objectManager->create(
            \Magento\Framework\Api\ExtensionAttribute\Config\Reader::class,
            ['fileResolver' => $extensionConfigFileResolverMock]
        );
        /** @var \Magento\Framework\Api\ExtensionAttribute\Config $config */
        $config = $objectManager->create(
            \Magento\Framework\Api\ExtensionAttribute\Config::class,
            ['reader' => $configReader]
        );

        /** @var \Magento\Framework\Api\ExtensionAttribute\JoinProcessorHelper $extensionAttributesProcessorHelper */
        $extensionAttributesProcessorHelper = $objectManager->create(
            \Magento\Framework\Api\ExtensionAttribute\JoinProcessorHelper::class,
            ['config' => $config]
        );

        /** @var \Magento\Framework\Api\ExtensionAttribute\JoinProcessor $extensionAttributesProcessor */
        $extensionAttributesProcessor = $objectManager->create(
            \Magento\Framework\Api\ExtensionAttribute\JoinProcessor::class,
            ['joinProcessorHelper' => $extensionAttributesProcessorHelper]
        );
        /** @var \Magento\Catalog\Model\ResourceModel\Product\Collection $collection */
        $collection = $objectManager->create(\Magento\Catalog\Model\ResourceModel\Product\Collection::class);
        $extensionAttributesProcessor->process($collection);
        $config->reset();

        $catalogProductEntity = $this->appResource->getTableName('catalog_product_entity');
        $catalogInventoryStockItem = $this->appResource->getTableName('cataloginventory_stock_item');
        $reviews = $this->appResource->getTableName('reviews');
        $expectedSql = <<<EXPECTED_SQL
SELECT `e`.*,
     `extension_attribute_stock_item`.`qty` AS `extension_attribute_stock_item_qty`,
     `extension_attribute_reviews`.`comment` AS `extension_attribute_reviews_comment`,
     `extension_attribute_reviews`.`rating` AS `extension_attribute_reviews_rating`,
     `extension_attribute_reviews`.`date` AS `extension_attribute_reviews_date` FROM `$catalogProductEntity` AS `e`
 LEFT JOIN `$catalogInventoryStockItem` AS `extension_attribute_stock_item` ON e.id = extension_attribute_stock_item.id
 LEFT JOIN `$reviews` AS `extension_attribute_reviews` ON e.id = extension_attribute_reviews.product_id
EXPECTED_SQL;
        $resultSql = $collection->getSelectSql(true);
        $formattedResultSql = str_replace(',', ",\n    ", $resultSql);
        $this->assertStringContainsString($expectedSql, $formattedResultSql);
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/products.php
     */
    public function testGetListWithExtensionAttributesAbstractModel()
    {
        /** @var \Magento\Framework\ObjectManagerInterface */
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        /** @var \Magento\Catalog\Api\ProductRepositoryInterface $productRepository */
        $productRepository = $objectManager->create(\Magento\Catalog\Api\ProductRepositoryInterface::class);

        $firstProductId = (int)$productRepository->get('simple')->getId();
        $firstProductQty = 11;
        $secondProductId = (int)$productRepository->get('custom-design-simple-product')->getId();
        $secondProductQty = 22;
        /** @var \Magento\CatalogInventory\Api\StockItemRepositoryInterface $stockItemRepository */
        $stockItemRepository = $objectManager->get(\Magento\CatalogInventory\Api\StockItemRepositoryInterface::class);

        /** Prepare stock items */
        $firstStockItem = $productRepository->getById($firstProductId)->getExtensionAttributes()->getStockItem();
        $firstStockItem->setQty($firstProductQty);
        $stockItemRepository->save($firstStockItem);
        $this->assertEquals(
            $firstProductQty,
            $productRepository->getById($firstProductId)->getExtensionAttributes()->getStockItem()->getQty(),
            'Precondition failed.'
        );
        $secondStockItem = $productRepository->getById($secondProductId)->getExtensionAttributes()->getStockItem();
        $secondStockItem->setQty($secondProductQty);
        $stockItemRepository->save($secondStockItem);
        $this->assertEquals(
            $secondProductQty,
            $productRepository->getById($secondProductId)->getExtensionAttributes()->getStockItem()->getQty(),
            'Precondition failed.'
        );

        /** @var \Magento\Framework\Api\Search\FilterGroup $searchCriteriaGroup */
        $searchCriteriaGroup = $objectManager->create(\Magento\Framework\Api\Search\FilterGroup::class);
        /** @var \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria */
        $searchCriteria = $objectManager->create(\Magento\Framework\Api\SearchCriteriaInterface::class);
        $searchCriteria->setFilterGroups([$searchCriteriaGroup]);
        $products = $productRepository->getList($searchCriteria)->getItems();

        /** Ensure that simple extension attributes were populated correctly */
        $this->assertEquals(
            $firstProductQty,
            $products[$firstProductId]->getExtensionAttributes()->getTestStockItemQty()
        );
        $this->assertEquals(
            $secondProductQty,
            $products[$secondProductId]->getExtensionAttributes()->getTestStockItemQty()
        );

        /** Check population of complex extension attributes */
        $this->assertEquals(
            $firstProductQty,
            $products[$firstProductId]->getExtensionAttributes()->getTestStockItem()->getQty()
        );
        $this->assertNotEmpty($products[$firstProductId]->getExtensionAttributes()->getTestStockItem()->getItemId());

        $this->assertArrayNotHasKey(
            'extension_attribute_test_stock_item_qty_qty',
            $products[$firstProductId]->getData(),
            "Selected extension field should be unset after it is added to extension attributes object."
        );
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoDataFixture Magento/Customer/_files/customer_group.php
     */
    public function testGetListWithExtensionAttributesAbstractObject()
    {
        $customerId = 1;
        $customerGroupName = 'General';
        $taxClassId = 3;
        /** @var \Magento\Framework\ObjectManagerInterface */
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();

        /** @var \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository */
        $customerRepository = $objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
        /** @var \Magento\Framework\Api\Search\FilterGroup $searchCriteriaGroup */
        $searchCriteriaGroup = $objectManager->create(\Magento\Framework\Api\Search\FilterGroup::class);
        /** @var \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria */
        $searchCriteria = $objectManager->create(\Magento\Framework\Api\SearchCriteriaInterface::class);
        $searchCriteria->setFilterGroups([$searchCriteriaGroup]);
        $customers = $customerRepository->getList($searchCriteria)->getItems();

        /** Ensure that simple extension attributes were populated correctly */
        $customer = $customers[0];
        $this->assertEquals($customerId, $customer->getId(), 'Precondition failed');
        $this->assertEquals($customerGroupName, $customer->getExtensionAttributes()->getTestGroupCode());

        /** Check population of complex extension attributes */
        $this->assertEquals($taxClassId, $customer->getExtensionAttributes()->getTestGroup()->getTaxClassId());
        $this->assertEquals($customerGroupName, $customer->getExtensionAttributes()->getTestGroup()->getCode());
    }

    public function testGetListWithFilterBySimpleDummyAttributeWithMapping()
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $groupRepository = $objectManager->create(\Magento\Customer\Api\GroupRepositoryInterface::class);
        /** @var \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder */
        $searchCriteriaBuilder = $objectManager->create(\Magento\Framework\Api\SearchCriteriaBuilder::class);
        $builder = $objectManager->create(\Magento\Framework\Api\FilterBuilder::class);
        $joinedExtensionAttribute = 'test_dummy_attribute';
        $joinedExtensionAttributeValue = 'website_id';
        $filter = $builder->setField($joinedExtensionAttribute)
            ->setValue($joinedExtensionAttributeValue)
            ->create();
        $searchCriteriaBuilder->addFilters([$filter]);
        $searchResults = $groupRepository->getList($searchCriteriaBuilder->create());
        $items = $searchResults->getItems();
        $this->assertCount(1, $items, 'Filtration by extension attribute does not work.');
        $expectedGroupCode = 'General';
        $this->assertEquals($expectedGroupCode, $items[0]->getCode(), 'Invalid group loaded.');
        $this->assertNotNull($items[0]->getExtensionAttributes(), "Extension attributes not loaded");
        $this->assertEquals(
            $joinedExtensionAttributeValue,
            $items[0]->getExtensionAttributes()->getTestDummyAttribute(),
            "Extension attributes were not loaded correctly"
        );
    }

    public function testGetListWithFilterByComplexDummyAttributeWithSetterMapping()
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $groupRepository = $objectManager->create(\Magento\Customer\Api\GroupRepositoryInterface::class);
        /** @var \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder */
        $searchCriteriaBuilder = $objectManager->create(\Magento\Framework\Api\SearchCriteriaBuilder::class);
        $builder = $objectManager->create(\Magento\Framework\Api\FilterBuilder::class);
        $joinedExtensionAttribute = 'test_complex_dummy_attribute.frontend_label';
        $joinedExtensionAttributeValue = 'firstname';
        $filter = $builder->setField($joinedExtensionAttribute)
            ->setValue($joinedExtensionAttributeValue)
            ->create();
        $searchCriteriaBuilder->addFilters([$filter]);
        $searchResults = $groupRepository->getList($searchCriteriaBuilder->create());
        $items = $searchResults->getItems();
        $this->assertCount(1, $items, 'Filtration by extension attribute does not work.');
        $expectedGroupCode = 'General';
        $this->assertEquals($expectedGroupCode, $items[0]->getCode(), 'Invalid group loaded.');
        $this->assertNotNull($items[0]->getExtensionAttributes(), "Extension attributes not loaded");
        $this->assertNotNull(
            $items[0]->getExtensionAttributes()->getTestComplexDummyAttribute(),
            "Complex extension attribute not loaded"
        );
        $this->assertEquals(
            'user',
            $items[0]->getExtensionAttributes()->getTestComplexDummyAttribute()->getAttributeCode(),
            "Extension attributes were not loaded correctly"
        );
        $this->assertEquals(
            $joinedExtensionAttributeValue,
            $items[0]->getExtensionAttributes()->getTestComplexDummyAttribute()->getFrontendLabel(),
            "Extension attributes were not loaded correctly"
        );
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testGetListWithExtensionAttributesAutoGeneratedRepository()
    {
        $this->markTestSkipped(
            'Invoice repository is not autogenerated anymore and does not have joined extension attributes'
        );
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $searchCriteriaBuilder = $objectManager->create(\Magento\Framework\Api\SearchCriteriaBuilder::class);
        /** @var \Magento\Sales\Api\InvoiceRepositoryInterface $invoiceRepository */
        $invoiceRepository = $objectManager->create(\Magento\Sales\Api\InvoiceRepositoryInterface::class);
        $invoices = $invoiceRepository->getList($searchCriteriaBuilder->create())->getItems();
        $this->assertCount(1, $invoices, "Invalid number of loaded invoices.");
        $invoice = reset($invoices);

        /** @var \Magento\Eav\Model\Entity\Attribute $joinedEntity */
        $joinedEntity = $objectManager->create(\Magento\Eav\Model\Entity\Attribute::class);
        $joinedEntity->load($invoice->getId());
        $joinedExtensionAttributeValue = $joinedEntity->getAttributeCode();

        $this->assertNotNull($invoice->getExtensionAttributes(), "Extension attributes not loaded");
        $this->assertEquals(
            $joinedExtensionAttributeValue,
            $invoice->getExtensionAttributes()->getTestDummyAttribute(),
            "Extension attributes were not loaded correctly"
        );
    }
}
