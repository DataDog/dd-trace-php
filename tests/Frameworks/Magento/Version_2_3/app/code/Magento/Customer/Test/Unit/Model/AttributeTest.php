<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Customer\Test\Unit\Model;

use Magento\Customer\Model\Attribute;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\Metadata\AttributeMetadataCache;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AttributeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Customer\Model\Attribute
     */
    protected $attribute;

    /**
     * @var \Magento\Framework\Model\Context|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $contextMock;

    /**
     * @var \Magento\Framework\Registry|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $registryMock;

    /**
     * @var \Magento\Framework\Api\AttributeValueFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $attributeValueFactoryMock;

    /**
     * @var \Magento\Eav\Model\Config|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $configMock;

    /**
     * @var \Magento\Eav\Model\Entity\TypeFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $typeFactoryMock;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $storeManagerMock;

    /**
     * @var \Magento\Eav\Model\ResourceModel\Helper|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $helperMock;

    /**
     * @var \Magento\Framework\Validator\UniversalFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $universalFactoryMock;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\TimezoneInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $timezoneMock;

    /**
     * @var \Magento\Framework\Model\ResourceModel\AbstractResource|\PHPUnit\Framework\MockObject\MockObject
     */
    private $resourceMock;

    /**
     * @var \Magento\Catalog\Model\Product\ReservedAttributeList|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $reservedAttributeListMock;

    /**
     * @var \Magento\Framework\Locale\ResolverInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resolverMock;

    /**
     * @var \Magento\Framework\App\CacheInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $cacheManager;

    /**
     * @var \Magento\Framework\Event\ManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $eventDispatcher;

    /**
     * @var \Magento\Eav\Api\Data\AttributeOptionInterfaceFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $attributeOptionFactoryMock;

    /**
     * @var \Magento\Framework\Reflection\DataObjectProcessor|\PHPUnit\Framework\MockObject\MockObject
     */
    private $dataObjectProcessorMock;

    /**
     * @var \Magento\Framework\Api\DataObjectHelper|\PHPUnit\Framework\MockObject\MockObject
     */
    private $dataObjectHelperMock;

    /**
     * @var \Magento\Framework\Indexer\IndexerRegistry|\PHPUnit\Framework\MockObject\MockObject
     */
    private $indexerRegistryMock;

    /**
     * @var \Magento\Framework\Api\ExtensionAttributesFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $extensionAttributesFactory;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTimeFormatterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $dateTimeFormatter;

    /**
     * @var \Magento\Customer\Model\Metadata\AttributeMetadataCache|\PHPUnit\Framework\MockObject\MockObject
     */
    private $attributeMetadataCacheMock;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @return void
     */
    protected function setUp(): void
    {
        $objectManagerHelper = new ObjectManagerHelper($this);
        $this->contextMock = $this->getMockBuilder(\Magento\Framework\Model\Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->registryMock = $this->getMockBuilder(\Magento\Framework\Registry::class)
            ->getMock();
        $this->extensionAttributesFactory = $this->getMockBuilder(
            \Magento\Framework\Api\ExtensionAttributesFactory::class
        )
            ->disableOriginalConstructor()
            ->getMock();
        $this->attributeValueFactoryMock = $this->getMockBuilder(\Magento\Framework\Api\AttributeValueFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->configMock = $this->getMockBuilder(\Magento\Eav\Model\Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->typeFactoryMock = $this->getMockBuilder(\Magento\Eav\Model\Entity\TypeFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->storeManagerMock = $this->getMockBuilder(\Magento\Store\Model\StoreManagerInterface::class)
            ->getMock();
        $this->helperMock = $this->getMockBuilder(\Magento\Eav\Model\ResourceModel\Helper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->universalFactoryMock = $this->getMockBuilder(\Magento\Framework\Validator\UniversalFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->attributeOptionFactoryMock =
            $this->getMockBuilder(\Magento\Eav\Api\Data\AttributeOptionInterfaceFactory::class)
                ->disableOriginalConstructor()
                ->getMock();
        $this->dataObjectProcessorMock = $this->getMockBuilder(\Magento\Framework\Reflection\DataObjectProcessor::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->dataObjectHelperMock = $this->getMockBuilder(\Magento\Framework\Api\DataObjectHelper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->timezoneMock = $this->getMockBuilder(\Magento\Framework\Stdlib\DateTime\TimezoneInterface::class)
            ->getMock();
        $this->reservedAttributeListMock = $this->getMockBuilder(
            \Magento\Catalog\Model\Product\ReservedAttributeList::class
        )
            ->disableOriginalConstructor()
            ->getMock();
        $this->resolverMock = $this->getMockBuilder(\Magento\Framework\Locale\ResolverInterface::class)
            ->getMock();
        $this->dateTimeFormatter = $this->createMock(
            \Magento\Framework\Stdlib\DateTime\DateTimeFormatterInterface::class
        );

        $this->resourceMock = $this->getMockBuilder(\Magento\Framework\Model\ResourceModel\AbstractResource::class)
            ->setMethods(['_construct', 'getConnection', 'getIdFieldName', 'saveInSetIncluding'])
            ->getMockForAbstractClass();
        $this->cacheManager = $this->getMockBuilder(\Magento\Framework\App\CacheInterface::class)
            ->getMock();
        $this->eventDispatcher = $this->getMockBuilder(\Magento\Framework\Event\ManagerInterface::class)
            ->getMock();

        $this->contextMock
            ->expects($this->any())
            ->method('getCacheManager')
            ->willReturn($this->cacheManager);
        $this->contextMock
            ->expects($this->any())
            ->method('getEventDispatcher')
            ->willReturn($this->eventDispatcher);

        $this->indexerRegistryMock = $this->getMockBuilder(\Magento\Framework\Indexer\IndexerRegistry::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->attributeMetadataCacheMock = $this->getMockBuilder(AttributeMetadataCache::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->attribute = $objectManagerHelper->getObject(
            Attribute::class,
            [
                'context' => $this->contextMock,
                'registry' => $this->registryMock,
                'extensionFactory' => $this->extensionAttributesFactory,
                'attributeValueFactory' => $this->attributeValueFactoryMock,
                'eavConfig' => $this->configMock,
                'typeFactory' => $this->typeFactoryMock,
                'storeManager' => $this->storeManagerMock,
                'helper' => $this->helperMock,
                'universalFactory' => $this->universalFactoryMock,
                'attributeOptionFactory' => $this->attributeOptionFactoryMock,
                'dataObjectProcessor' => $this->dataObjectProcessorMock,
                'dataObjectHelper' => $this->dataObjectHelperMock,
                'timezone' => $this->timezoneMock,
                'reservedAttributeList' => $this->reservedAttributeListMock,
                'resolver' => $this->resolverMock,
                'dateTimeFormatter' => $this->dateTimeFormatter,
                'indexerRegistry' => $this->indexerRegistryMock,
                'resource' => $this->resourceMock,
                'attributeMetadataCache' => $this->attributeMetadataCacheMock
            ]
        );
    }

    public function testAfterSaveEavCache()
    {
        $this->configMock
            ->expects($this->once())
            ->method('clear');
        $this->attributeMetadataCacheMock
            ->expects($this->once())
            ->method('clean');
        $this->attribute->afterSave();
    }

    public function testAfterDeleteEavCache()
    {
        $this->configMock
            ->expects($this->once())
            ->method('clear');
        $this->attributeMetadataCacheMock
            ->expects($this->once())
            ->method('clean');
        $this->attribute->afterDelete();
    }

    public function testInvalidate()
    {
        /** @var IndexerInterface|\PHPUnit\Framework\MockObject\MockObject $indexerMock */
        $indexerMock = $this->getMockBuilder(\Magento\Framework\Indexer\IndexerInterface::class)
            ->getMockForAbstractClass();

        $this->indexerRegistryMock->expects($this->once())
            ->method('get')
            ->with(Customer::CUSTOMER_GRID_INDEXER_ID)
            ->willReturn($indexerMock);

        $indexerMock->expects($this->once())
            ->method('invalidate');

        $this->attribute->invalidate();
    }

    /**
     * @param int $isSearchableInGrid
     * @param string $frontendInput
     * @param bool $result
     * @dataProvider dataProviderCanBeSearchableInGrid
     */
    public function testCanBeSearchableInGrid($isSearchableInGrid, $frontendInput, $result)
    {
        $this->attribute->setData('is_searchable_in_grid', $isSearchableInGrid);
        $this->attribute->setData(AttributeInterface::FRONTEND_INPUT, $frontendInput);

        $this->assertEquals($result, $this->attribute->canBeSearchableInGrid());
    }

    /**
     * @return array
     */
    public function dataProviderCanBeSearchableInGrid()
    {
        return [
            [0, 'text', false],
            [0, 'textarea', false],
            [1, 'text', true],
            [1, 'textarea', true],
            [1, 'date', false],
            [1, 'boolean', false],
            [1, 'select', false],
            [1, 'media_image', false],
            [1, 'gallery', false],
            [1, 'multiselect', false],
            [1, 'image', false],
            [1, 'price', false],
            [1, 'weight', false],
        ];
    }

    /**
     * @param int $isFilterableInGrid
     * @param string $frontendInput
     * @param bool $result
     * @dataProvider dataProviderCanBeFilterableInGrid
     */
    public function testCanBeFilterableInGrid($isFilterableInGrid, $frontendInput, $result)
    {
        $this->attribute->setData('is_filterable_in_grid', $isFilterableInGrid);
        $this->attribute->setData(AttributeInterface::FRONTEND_INPUT, $frontendInput);

        $this->assertEquals($result, $this->attribute->canBeFilterableInGrid());
    }

    /**
     * @return array
     */
    public function dataProviderCanBeFilterableInGrid()
    {
        return [
            [0, 'text', false],
            [0, 'date', false],
            [0, 'select', false],
            [0, 'boolean', false],
            [1, 'text', true],
            [1, 'date', true],
            [1, 'select', true],
            [1, 'boolean', true],
            [1, 'textarea', false],
            [1, 'media_image', false],
            [1, 'gallery', false],
            [1, 'multiselect', false],
            [1, 'image', false],
            [1, 'price', false],
            [1, 'weight', false],
        ];
    }
}
