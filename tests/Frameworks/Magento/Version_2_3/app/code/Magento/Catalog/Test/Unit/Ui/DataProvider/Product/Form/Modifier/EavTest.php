<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\Eav;
use Magento\Eav\Model\Config;
use Magento\Eav\Model\Entity\Attribute\Source\SourceInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Ui\DataProvider\EavValidationRules;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Group\Collection as GroupCollection;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Group\CollectionFactory as GroupCollectionFactory;
use Magento\Eav\Model\Entity\Attribute\Group;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute as EavAttribute;
use Magento\Eav\Model\Entity\Type as EntityType;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection as AttributeCollection;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Ui\DataProvider\Mapper\FormElement as FormElementMapper;
use Magento\Ui\DataProvider\Mapper\MetaProperties as MetaPropertiesMapper;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Catalog\Api\ProductAttributeGroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Framework\Api\AttributeInterface;
use Magento\Eav\Api\Data\AttributeGroupInterface;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Framework\Currency;
use Magento\Framework\Locale\Currency as CurrencyLocale;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\Stdlib\ArrayManager;
use Magento\Catalog\Model\ResourceModel\Eav\AttributeFactory as EavAttributeFactory;
use Magento\Framework\Event\ManagerInterface;

/**
 * Class EavTest
 *
 * @method Eav getModel
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class EavTest extends AbstractModifierTest
{
    /**
     * @var Config|\PHPUnit\Framework\MockObject\MockObject
     */
    private $eavConfigMock;

    /**
     * @var EavValidationRules|\PHPUnit\Framework\MockObject\MockObject
     */
    private $eavValidationRulesMock;

    /**
     * @var RequestInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $requestMock;

    /**
     * @var GroupCollectionFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $groupCollectionFactoryMock;

    /**
     * @var GroupCollection|\PHPUnit\Framework\MockObject\MockObject
     */
    private $groupCollectionMock;

    /**
     * @var Group|\PHPUnit\Framework\MockObject\MockObject
     */
    private $groupMock;

    /**
     * @var EavAttribute|\PHPUnit\Framework\MockObject\MockObject
     */
    private $attributeMock;

    /**
     * @var EntityType|\PHPUnit\Framework\MockObject\MockObject
     */
    private $entityTypeMock;

    /**
     * @var AttributeCollectionFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $attributeCollectionFactoryMock;

    /**
     * @var AttributeCollection|\PHPUnit\Framework\MockObject\MockObject
     */
    private $attributeCollectionMock;

    /**
     * @var StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $storeManagerMock;

    /**
     * @var FormElementMapper|\PHPUnit\Framework\MockObject\MockObject
     */
    private $formElementMapperMock;

    /**
     * @var MetaPropertiesMapper|\PHPUnit\Framework\MockObject\MockObject
     */
    private $metaPropertiesMapperMock;

    /**
     * @var SearchCriteriaBuilder|\PHPUnit\Framework\MockObject\MockObject
     */
    private $searchCriteriaBuilderMock;

    /**
     * @var ProductAttributeGroupRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $attributeGroupRepositoryMock;

    /**
     * @var SearchCriteria|\PHPUnit\Framework\MockObject\MockObject
     */
    private $searchCriteriaMock;

    /**
     * @var SortOrderBuilder|\PHPUnit\Framework\MockObject\MockObject
     */
    private $sortOrderBuilderMock;

    /**
     * @var ProductAttributeRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $attributeRepositoryMock;

    /**
     * @var AttributeGroupInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $attributeGroupMock;

    /**
     * @var SearchResultsInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $searchResultsMock;

    /**
     * @var Attribute|\PHPUnit\Framework\MockObject\MockObject
     */
    private $eavAttributeMock;

    /**
     * @var StoreInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $storeMock;

    /**
     * @var Currency|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $currencyMock;

    /**
     * @var CurrencyLocale|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $currencyLocaleMock;

    /**
     * @var ProductAttributeInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $productAttributeMock;

    /**
     * @var ArrayManager|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $arrayManagerMock;

    /**
     * @var EavAttributeFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $eavAttributeFactoryMock;

    /**
     * @var ManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $eventManagerMock;

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var Eav
     */
    protected $eav;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager = new ObjectManager($this);
        $this->eavConfigMock = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->eavValidationRulesMock = $this->getMockBuilder(EavValidationRules::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->requestMock = $this->getMockBuilder(RequestInterface::class)
            ->getMockForAbstractClass();
        $this->groupCollectionFactoryMock = $this->getMockBuilder(GroupCollectionFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->groupCollectionMock =
            $this->getMockBuilder(GroupCollection::class)
                ->disableOriginalConstructor()
                ->getMock();
        $this->attributeMock = $this->getMockBuilder(EavAttribute::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->groupMock = $this->getMockBuilder(Group::class)
            ->disableOriginalConstructor()
            ->setMethods(['getAttributeGroupCode'])
            ->getMock();
        $this->entityTypeMock = $this->getMockBuilder(EntityType::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->attributeCollectionFactoryMock = $this->getMockBuilder(AttributeCollectionFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->attributeCollectionMock = $this->getMockBuilder(AttributeCollection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->storeManagerMock = $this->getMockBuilder(StoreManagerInterface::class)
            ->getMockForAbstractClass();
        $this->formElementMapperMock = $this->getMockBuilder(FormElementMapper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->metaPropertiesMapperMock = $this->getMockBuilder(MetaPropertiesMapper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->searchCriteriaBuilderMock = $this->getMockBuilder(SearchCriteriaBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->attributeGroupRepositoryMock = $this->getMockBuilder(ProductAttributeGroupRepositoryInterface::class)
            ->getMockForAbstractClass();
        $this->attributeGroupMock = $this->getMockBuilder(AttributeGroupInterface::class)
            ->setMethods(['getAttributeGroupCode', 'getApplyTo'])
            ->getMockForAbstractClass();
        $this->attributeRepositoryMock = $this->getMockBuilder(ProductAttributeRepositoryInterface::class)
            ->getMockForAbstractClass();
        $this->searchCriteriaMock = $this->getMockBuilder(SearchCriteria::class)
            ->disableOriginalConstructor()
            ->setMethods(['getItems'])
            ->getMock();
        $this->sortOrderBuilderMock = $this->getMockBuilder(SortOrderBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->searchResultsMock = $this->getMockBuilder(SearchResultsInterface::class)
            ->getMockForAbstractClass();
        $this->eavAttributeMock = $this->getMockBuilder(Attribute::class)
            ->setMethods(
                [
                    'load',
                    'getAttributeGroupCode',
                    'getApplyTo',
                    'getFrontendInput',
                    'getAttributeCode',
                    'usesSource',
                    'getSource',
                ]
            )
            ->disableOriginalConstructor()
            ->getMock();
        $this->productAttributeMock = $this->getMockBuilder(ProductAttributeInterface::class)
            ->setMethods(['getValue'])
            ->getMockForAbstractClass();
        $this->arrayManagerMock = $this->getMockBuilder(ArrayManager::class)
            ->getMock();
        $this->eavAttributeFactoryMock = $this->getMockBuilder(EavAttributeFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->eventManagerMock = $this->getMockBuilder(ManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->eavAttributeFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($this->eavAttributeMock);
        $this->groupCollectionFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($this->groupCollectionMock);
        $this->groupCollectionMock->expects($this->any())
            ->method('setAttributeSetFilter')
            ->willReturnSelf();
        $this->groupCollectionMock->expects($this->any())
            ->method('setSortOrder')
            ->willReturnSelf();
        $this->groupCollectionMock->expects($this->any())
            ->method('load')
            ->willReturnSelf();
        $this->groupCollectionMock->expects($this->any())
            ->method('getIterator')
            ->willReturn(new \ArrayIterator([$this->groupMock]));
        $this->attributeCollectionMock->expects($this->any())
            ->method('addFieldToSelect')
            ->willReturnSelf();
        $this->attributeCollectionMock->expects($this->any())
            ->method('load')
            ->willReturnSelf();
        $this->eavConfigMock->expects($this->any())
            ->method('getEntityType')
            ->willReturn($this->entityTypeMock);
        $this->entityTypeMock->expects($this->any())
            ->method('getAttributeCollection')
            ->willReturn($this->attributeCollectionMock);
        $this->productMock->expects($this->any())
            ->method('getAttributes')
            ->willReturn([$this->attributeMock,]);
        $this->storeMock = $this->getMockBuilder(StoreInterface::class)
            ->setMethods(['load', 'getId', 'getConfig', 'getBaseCurrencyCode'])
            ->getMockForAbstractClass();
        $this->currencyMock = $this->getMockBuilder(Currency::class)
            ->disableOriginalConstructor()
            ->setMethods(['toCurrency'])
            ->getMock();
        $this->currencyLocaleMock = $this->getMockBuilder(CurrencyLocale::class)
            ->disableOriginalConstructor()
            ->setMethods(['getCurrency'])
            ->getMock();
        $this->eavAttributeMock->expects($this->any())
            ->method('load')
            ->willReturnSelf();

        $this->eav =$this->getModel();
        $this->objectManager->setBackwardCompatibleProperty(
            $this->eav,
            'localeCurrency',
            $this->currencyLocaleMock
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function createModel()
    {
        return $this->objectManager->getObject(
            Eav::class,
            [
                'locator' => $this->locatorMock,
                'eavValidationRules' => $this->eavValidationRulesMock,
                'eavConfig' => $this->eavConfigMock,
                'request' => $this->requestMock,
                'groupCollectionFactory' => $this->groupCollectionFactoryMock,
                'storeManager' => $this->storeManagerMock,
                'formElementMapper' => $this->formElementMapperMock,
                'metaPropertiesMapper' => $this->metaPropertiesMapperMock,
                'searchCriteriaBuilder' => $this->searchCriteriaBuilderMock,
                'attributeGroupRepository' => $this->attributeGroupRepositoryMock,
                'sortOrderBuilder' => $this->sortOrderBuilderMock,
                'attributeRepository' => $this->attributeRepositoryMock,
                'arrayManager' => $this->arrayManagerMock,
                'eavAttributeFactory' => $this->eavAttributeFactoryMock,
                '_eventManager' => $this->eventManagerMock,
                'attributeCollectionFactory' => $this->attributeCollectionFactoryMock
            ]
        );
    }

    public function testModifyData()
    {
        $sourceData = [
            '1' => [
                'product' => [
                    ProductAttributeInterface::CODE_PRICE => '19.99'
                ]
            ]
        ];

        $this->attributeCollectionFactoryMock->expects($this->once())->method('create')
            ->willReturn($this->attributeCollectionMock);

        $this->attributeCollectionMock->expects($this->any())->method('getItems')
            ->willReturn([$this->eavAttributeMock]);

        $this->locatorMock->expects($this->any())->method('getProduct')
            ->willReturn($this->productMock);

        $this->productMock->expects($this->any())->method('getId')
            ->willReturn(1);
        $this->productMock->expects($this->once())->method('getAttributeSetId')
            ->willReturn(4);
        $this->productMock->expects($this->once())->method('getData')
            ->with(ProductAttributeInterface::CODE_PRICE)->willReturn('19.9900');

        $this->searchCriteriaBuilderMock->expects($this->any())->method('addFilter')
            ->willReturnSelf();
        $this->searchCriteriaBuilderMock->expects($this->any())->method('create')
            ->willReturn($this->searchCriteriaMock);
        $this->attributeGroupRepositoryMock->expects($this->any())->method('getList')
            ->willReturn($this->searchCriteriaMock);
        $this->searchCriteriaMock->expects($this->once())->method('getItems')
            ->willReturn([$this->attributeGroupMock]);
        $this->sortOrderBuilderMock->expects($this->once())->method('setField')
            ->willReturnSelf();
        $this->sortOrderBuilderMock->expects($this->once())->method('setAscendingDirection')
            ->willReturnSelf();
        $dataObjectMock = $this->createMock(\Magento\Framework\Api\AbstractSimpleObject::class);
        $this->sortOrderBuilderMock->expects($this->once())->method('create')
            ->willReturn($dataObjectMock);

        $this->searchCriteriaBuilderMock->expects($this->any())->method('addFilter')
            ->willReturnSelf();
        $this->searchCriteriaBuilderMock->expects($this->once())->method('addSortOrder')
            ->willReturnSelf();
        $this->searchCriteriaBuilderMock->expects($this->any())->method('create')
            ->willReturn($this->searchCriteriaMock);

        $this->attributeRepositoryMock->expects($this->once())->method('getList')
            ->with($this->searchCriteriaMock)
            ->willReturn($this->searchResultsMock);
        $this->eavAttributeMock->expects($this->any())->method('getAttributeGroupCode')
            ->willReturn('product-details');
        $this->eavAttributeMock->expects($this->once())->method('getApplyTo')
            ->willReturn([]);
        $this->eavAttributeMock->expects($this->once())->method('getFrontendInput')
            ->willReturn('price');
        $this->eavAttributeMock->expects($this->any())->method('getAttributeCode')
            ->willReturn(ProductAttributeInterface::CODE_PRICE);
        $this->searchResultsMock->expects($this->once())->method('getItems')
            ->willReturn([$this->eavAttributeMock]);

        $this->storeMock->expects(($this->once()))->method('getBaseCurrencyCode')
            ->willReturn('en_US');
        $this->storeManagerMock->expects($this->once())->method('getStore')
            ->willReturn($this->storeMock);
        $this->currencyMock->expects($this->once())->method('toCurrency')
            ->willReturn('19.99');
        $this->currencyLocaleMock->expects($this->once())->method('getCurrency')
            ->willReturn($this->currencyMock);

        $this->assertEquals($sourceData, $this->eav->modifyData([]));
    }

    /**
     * @param int|null $productId
     * @param bool $productRequired
     * @param string|null $attrValue
     * @param array $expected
     * @param bool $locked
     * @covers \Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\Eav::isProductExists
     * @covers \Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\Eav::setupAttributeMeta
     * @dataProvider setupAttributeMetaDataProvider
     */
    public function testSetupAttributeMetaDefaultAttribute(
        $productId,
        bool $productRequired,
        $attrValue,
        array $expected,
        $locked = false
    ) : void {
        $configPath = 'arguments/data/config';
        $groupCode = 'product-details';
        $sortOrder = '0';
        $attributeOptions = [
            ['value' => 1, 'label' => 'Int label'],
            ['value' => 1.5, 'label' => 'Float label'],
            ['value' => true, 'label' => 'Boolean label'],
            ['value' => 'string', 'label' => 'String label'],
            ['value' => ['test1', 'test2'], 'label' => 'Array label'],
        ];
        $attributeOptionsExpected = [
            ['value' => '1', 'label' => 'Int label', '__disableTmpl' => true],
            ['value' => '1.5', 'label' => 'Float label', '__disableTmpl' => true],
            ['value' => '1', 'label' => 'Boolean label', '__disableTmpl' => true],
            ['value' => 'string', 'label' => 'String label', '__disableTmpl' => true],
            ['value' => ['test1', 'test2'], 'label' => 'Array label', '__disableTmpl' => true],
        ];

        $this->productMock->method('getId')->willReturn($productId);
        $this->productMock->expects($this->any())->method('isLockedAttribute')->willReturn($locked);
        $this->productAttributeMock->method('getIsRequired')->willReturn($productRequired);
        $this->productAttributeMock->method('getDefaultValue')->willReturn('required_value');
        $this->productAttributeMock->method('getAttributeCode')->willReturn('code');
        $this->productAttributeMock->method('getValue')->willReturn('value');

        $attributeMock = $this->getMockBuilder(AttributeInterface::class)
            ->setMethods(['getValue'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $attributeMock->method('getValue')->willReturn($attrValue);

        $this->productMock->method('getCustomAttribute')->willReturn($attributeMock);
        $this->eavAttributeMock->method('usesSource')->willReturn(true);

        $attributeSource = $this->getMockBuilder(SourceInterface::class)->getMockForAbstractClass();
        $attributeSource->method('getAllOptions')->willReturn($attributeOptions);

        $this->eavAttributeMock->method('getSource')->willReturn($attributeSource);

        $this->arrayManagerMock->method('set')
            ->with(
                $configPath,
                [],
                $expected
            )
            ->willReturn($expected);

        $this->arrayManagerMock->expects($this->any())
            ->method('merge')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(
                    function ($value) use ($attributeOptionsExpected) {
                        return isset($value['options']) ? $value['options'] === $attributeOptionsExpected : true;
                    }
                )
            )
            ->willReturn($expected);

        $this->arrayManagerMock->method('get')->willReturn([]);
        $this->arrayManagerMock->method('exists')->willReturn(true);

        $this->assertEquals(
            $expected,
            $this->eav->setupAttributeMeta($this->productAttributeMock, $groupCode, $sortOrder)
        );
    }

    /**
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function setupAttributeMetaDataProvider()
    {
        return [
            'default_null_prod_not_new_and_required' => [
                'productId' => 1,
                'productRequired' => true,
                'attrValue' => 'val',
                'expected' => [
                    'dataType' => null,
                    'formElement' => null,
                    'visible' => null,
                    'required' => true,
                    'notice' => null,
                    'default' => null,
                    'label' => new Phrase(null),
                    'code' => 'code',
                    'source' => 'product-details',
                    'scopeLabel' => '',
                    'globalScope' => false,
                    'sortOrder' => 0,
                    '__disableTmpl' => ['label' => true, 'code' => true]
                ],
            ],
            'default_null_prod_not_new_locked_and_required' => [
                'productId' => 1,
                'productRequired' => true,
                'attrValue' => 'val',
                'expected' => [
                    'dataType' => null,
                    'formElement' => null,
                    'visible' => null,
                    'required' => true,
                    'notice' => null,
                    'default' => null,
                    'label' => new Phrase(null),
                    'code' => 'code',
                    'source' => 'product-details',
                    'scopeLabel' => '',
                    'globalScope' => false,
                    'sortOrder' => 0,
                    '__disableTmpl' => ['label' => true, 'code' => true]
                ],
                'locked' => true,
            ],
            'default_null_prod_not_new_and_not_required' => [
                'productId' => 1,
                'productRequired' => false,
                'attrValue' => 'val',
                'expected' => [
                    'dataType' => null,
                    'formElement' => null,
                    'visible' => null,
                    'required' => false,
                    'notice' => null,
                    'default' => null,
                    'label' => new Phrase(null),
                    'code' => 'code',
                    'source' => 'product-details',
                    'scopeLabel' => '',
                    'globalScope' => false,
                    'sortOrder' => 0,
                    '__disableTmpl' => ['label' => true, 'code' => true]
                ],
            ],
            'default_null_prod_new_and_not_required' => [
                'productId' => null,
                'productRequired' => false,
                'attrValue' => null,
                'expected' => [
                    'dataType' => null,
                    'formElement' => null,
                    'visible' => null,
                    'required' => false,
                    'notice' => null,
                    'default' => 'required_value',
                    'label' => new Phrase(null),
                    'code' => 'code',
                    'source' => 'product-details',
                    'scopeLabel' => '',
                    'globalScope' => false,
                    'sortOrder' => 0,
                    '__disableTmpl' => ['label' => true, 'code' => true]
                ],
            ],
            'default_null_prod_new_locked_and_not_required' => [
                'productId' => null,
                'productRequired' => false,
                'attrValue' => null,
                'expected' => [
                    'dataType' => null,
                    'formElement' => null,
                    'visible' => null,
                    'required' => false,
                    'notice' => null,
                    'default' => 'required_value',
                    'label' => new Phrase(null),
                    'code' => 'code',
                    'source' => 'product-details',
                    'scopeLabel' => '',
                    'globalScope' => false,
                    'sortOrder' => 0,
                    '__disableTmpl' => ['label' => true, 'code' => true]
                ],
                'locked' => true,
            ],
            'default_null_prod_new_and_required' => [
                'productId' => null,
                'productRequired' => false,
                'attrValue' => null,
                'expected' => [
                    'dataType' => null,
                    'formElement' => null,
                    'visible' => null,
                    'required' => false,
                    'notice' => null,
                    'default' => 'required_value',
                    'label' => new Phrase(null),
                    'code' => 'code',
                    'source' => 'product-details',
                    'scopeLabel' => '',
                    'globalScope' => false,
                    'sortOrder' => 0,
                    '__disableTmpl' => ['label' => true, 'code' => true]
                ],
            ]
        ];
    }
}
