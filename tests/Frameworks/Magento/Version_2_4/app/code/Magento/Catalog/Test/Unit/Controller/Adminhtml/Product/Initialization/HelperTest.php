<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Controller\Adminhtml\Product\Initialization;

use Magento\Catalog\Api\Data\CategoryLinkInterface;
use Magento\Catalog\Api\Data\CategoryLinkInterfaceFactory;
use Magento\Catalog\Api\Data\ProductCustomOptionInterfaceFactory;
use Magento\Catalog\Api\Data\ProductExtensionInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\Data\ProductLinkTypeInterface;
use Magento\Catalog\Api\ProductRepositoryInterface as ProductRepository;
use Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper;
use Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper\AttributeFilter;
use Magento\Catalog\Controller\Adminhtml\Product\Initialization\StockDataFilter;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Filter\DateTime;
use Magento\Catalog\Model\Product\Initialization\Helper\ProductLinks;
use Magento\Catalog\Model\Product\Link\Resolver;
use Magento\Catalog\Model\Product\LinkTypeProvider;
use Magento\Catalog\Model\Product\Option;
use Magento\Catalog\Model\ProductLink\Link as ProductLink;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Entity\Attribute\Backend\DefaultBackend;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Locale\Format;
use Magento\Framework\Locale\FormatInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class HelperTest extends TestCase
{
    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @var ProductLinkInterfaceFactory|MockObject
     */
    protected $productLinkFactoryMock;

    /**
     * @var RequestInterface|MockObject
     */
    protected $requestMock;

    /**
     * @var StoreManagerInterface|MockObject
     */
    protected $storeManagerMock;

    /**
     * @var StockDataFilter|MockObject
     */
    protected $stockFilterMock;

    /**
     * @var Product|MockObject
     */
    protected $productMock;

    /**
     * @var ProductRepository|MockObject
     */
    protected $productRepositoryMock;

    /**
     * @var ProductCustomOptionInterfaceFactory|MockObject
     */
    protected $customOptionFactoryMock;

    /**
     * @var Resolver|MockObject
     */
    protected $linkResolverMock;

    /**
     * @var LinkTypeProvider|MockObject
     */
    protected $linkTypeProviderMock;

    /**
     * @var ProductLinks|MockObject
     */
    protected $productLinksMock;

    /**
     * @var AttributeFilter|MockObject
     */
    protected $attributeFilterMock;

    /**
     * @var MockObject
     */
    private $dateTimeFilterMock;

    /**
     * @var FormatInterface|MockObject
     */
    protected $localeFormatMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->productLinkFactoryMock = $this->getMockBuilder(ProductLinkInterfaceFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->productRepositoryMock = $this->createMock(ProductRepository::class);
        $this->requestMock = $this->getMockBuilder(RequestInterface::class)
            ->setMethods(['getPost'])
            ->getMockForAbstractClass();
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->stockFilterMock = $this->createMock(StockDataFilter::class);

        $this->productMock = $this->getMockBuilder(Product::class)
            ->setMethods(
                [
                    'getId',
                    'isLockedAttribute',
                    'lockAttribute',
                    'getAttributes',
                    'unlockAttribute',
                    'getOptionsReadOnly',
                    'getSku',
                ]
            )
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $productExtensionAttributes = $this->getMockBuilder(ProductExtensionInterface::class)
            ->setMethods(['getCategoryLinks', 'setCategoryLinks'])
            ->getMockForAbstractClass();
        $this->productMock->setExtensionAttributes($productExtensionAttributes);

        $this->customOptionFactoryMock = $this->getMockBuilder(ProductCustomOptionInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->productLinksMock = $this->createMock(ProductLinks::class);
        $this->linkTypeProviderMock = $this->createMock(LinkTypeProvider::class);
        $this->productLinksMock->expects($this->any())
            ->method('initializeLinks')
            ->willReturn($this->productMock);
        $this->attributeFilterMock = $this->createMock(AttributeFilter::class);
        $this->localeFormatMock = $this->createMock(Format::class);

        $this->dateTimeFilterMock = $this->createMock(DateTime::class);

        $categoryLinkFactoryMock = $this->getMockBuilder(CategoryLinkInterfaceFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $categoryLinkFactoryMock->method('create')
            ->willReturnCallback(function () {
                return $this->createMock(CategoryLinkInterface::class);
            });

        $this->helper = $this->objectManager->getObject(
            Helper::class,
            [
                'request' => $this->requestMock,
                'storeManager' => $this->storeManagerMock,
                'stockFilter' => $this->stockFilterMock,
                'productLinks' => $this->productLinksMock,
                'customOptionFactory' => $this->customOptionFactoryMock,
                'productLinkFactory' => $this->productLinkFactoryMock,
                'productRepository' => $this->productRepositoryMock,
                'linkTypeProvider' => $this->linkTypeProviderMock,
                'attributeFilter' => $this->attributeFilterMock,
                'localeFormat' => $this->localeFormatMock,
                'dateTimeFilter' => $this->dateTimeFilterMock,
                'categoryLinkFactory' => $categoryLinkFactoryMock,
            ]
        );

        $this->linkResolverMock = $this->createMock(Resolver::class);
        $helperReflection = new \ReflectionClass(get_class($this->helper));
        $resolverProperty = $helperReflection->getProperty('linkResolver');
        $resolverProperty->setAccessible(true);
        $resolverProperty->setValue($this->helper, $this->linkResolverMock);
    }

    /**
     * @param bool $isSingleStore
     * @param array $websiteIds
     * @param array $expWebsiteIds
     * @param array $links
     * @param array $linkTypes
     * @param array $expectedLinks
     * @param array|null $tierPrice
     * @dataProvider initializeDataProvider
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testInitialize(
        $isSingleStore,
        $websiteIds,
        $expWebsiteIds,
        $links,
        $linkTypes,
        $expectedLinks,
        $tierPrice = null
    ) {
        $this->linkTypeProviderMock->expects($this->once())
            ->method('getItems')
            ->willReturn($this->assembleLinkTypes($linkTypes));

        $optionsData = [
            'option1' => ['is_delete' => true, 'name' => 'name1', 'price' => '1', 'option_id' => ''],
            'option2' => ['is_delete' => false, 'name' => 'name2', 'price' => '2', 'option_id' => '13'],
            'option3' => ['is_delete' => false, 'name' => 'name3', 'price' => '3', 'option_id' => '14'],
        ];
        $specialFromDate = '2018-03-03 19:30:00';
        $productData = [
            'name' => 'Simple Product',
            'stock_data' => ['stock_data'],
            'options' => $optionsData,
            'website_ids' => $websiteIds,
            'special_from_date' => $specialFromDate,
        ];
        if (!empty($tierPrice)) {
            $productData = array_merge($productData, ['tier_price' => $tierPrice]);
        }

        $this->dateTimeFilterMock
            ->expects($this->once())
            ->method('filter')
            ->willReturnArgument(0);

        $this->setProductAttributes(
            [
                [
                    'code' => 'name',
                    'backend_type' => 'varchar',
                ],
                [
                    'code' => 'special_from_date',
                    'backend_type' => 'datetime',
                ]
            ]
        );

        $useDefaults = ['attributeCode1', 'attributeCode2'];

        $this->requestMock->expects($this->any())->method('getPost')->willReturnMap(
            [
                ['product', [], $productData],
                ['use_default', null, $useDefaults],
            ]
        );
        $this->linkResolverMock->expects($this->once())->method('getLinks')->willReturn($links);
        $this->stockFilterMock->expects($this->once())->method('filter')->with(['stock_data'])
            ->willReturn(['stock_data']);
        $this->productMock->expects($this->once())->method('isLockedAttribute')->with('media')->willReturn(true);
        $this->productMock->expects($this->once())->method('unlockAttribute')->with('media');
        $this->productMock->expects($this->once())->method('lockAttribute')->with('media');
        $this->productMock->expects($this->any())->method('getSku')->willReturn('sku');
        $this->productMock->expects($this->any())->method('getOptionsReadOnly')->willReturn(false);

        $customOptionMock = $this->getMockBuilder(Option::class)
            ->disableOriginalConstructor()
            ->setMethods(null)
            ->getMock();
        $firstExpectedCustomOption = clone $customOptionMock;
        $firstExpectedCustomOption->setData($optionsData['option2']);
        $secondExpectedCustomOption = clone $customOptionMock;
        $secondExpectedCustomOption->setData($optionsData['option3']);
        $this->customOptionFactoryMock->expects($this->any())
            ->method('create')
            ->willReturnMap(
                [
                    [
                        ['data' => $optionsData['option2']],
                        $firstExpectedCustomOption,
                    ],
                    [
                        ['data' => $optionsData['option3']],
                        $secondExpectedCustomOption,
                    ],
                ]
            );
        $website = $this->getMockBuilder(WebsiteInterface::class)
            ->getMockForAbstractClass();
        $website->expects($this->any())->method('getId')->willReturn(1);
        $this->storeManagerMock->expects($this->once())->method('isSingleStoreMode')->willReturn($isSingleStore);
        $this->storeManagerMock->expects($this->any())->method('getWebsite')->willReturn($website);
        $this->localeFormatMock->expects($this->any())
            ->method('getNumber')
            ->willReturnArgument(0);

        $this->assembleProductRepositoryMock($links);

        $this->productLinkFactoryMock->expects($this->any())
            ->method('create')
            ->willReturnCallback(
                function () {
                    return $this->getMockBuilder(ProductLink::class)
                        ->setMethods(null)
                        ->disableOriginalConstructor()
                        ->getMock();
                }
            );

        $this->attributeFilterMock->expects($this->any())->method('prepareProductAttributes')->willReturnArgument(1);

        $this->assertEquals($this->productMock, $this->helper->initialize($this->productMock));
        $this->assertEquals($expWebsiteIds, $this->productMock->getDataByKey('website_ids'));

        $productOptions = $this->productMock->getOptions();
        $this->assertCount(2, $productOptions);
        list($option2, $option3) = $productOptions;
        $this->assertEquals($optionsData['option2']['option_id'], $option2->getOptionId());
        $this->assertEquals('sku', $option2->getData('product_sku'));
        $this->assertEquals($optionsData['option3']['option_id'], $option3->getOptionId());
        $this->assertEquals('sku', $option2->getData('product_sku'));

        $productLinks = $this->productMock->getProductLinks();
        $this->assertCount(count($expectedLinks), $productLinks);
        $resultLinks = [];

        $this->assertEquals($tierPrice ?: [], $this->productMock->getData('tier_price'));

        foreach ($productLinks as $link) {
            $this->assertInstanceOf(ProductLink::class, $link);
            $this->assertEquals('sku', $link->getSku());
            $resultLinks[] = ['type' => $link->getLinkType(), 'linked_product_sku' => $link->getLinkedProductSku()];
        }

        $this->assertEquals($expectedLinks, $resultLinks);
        $this->assertEquals($specialFromDate, $this->productMock->getSpecialFromDate());
    }

    /**
     * Mock product attributes
     *
     * @param array $attributes
     */
    private function setProductAttributes(array $attributes): void
    {
        $attributesModels = [];
        foreach ($attributes as $attribute) {
            $attributeModel = $this->createMock(Attribute::class);
            $backendModel = $attribute['backend_model']
                ?? $this->createMock(DefaultBackend::class);
            $attributeModel->expects($this->any())
                ->method('getBackend')
                ->willReturn($backendModel);
            $attributeModel->expects($this->any())
                ->method('getAttributeCode')
                ->willReturn($attribute['code']);
            $backendModel->expects($this->any())
                ->method('getType')
                ->willReturn($attribute['backend_type']);
            $attributesModels[$attribute['code']] = $attributeModel;
        }
        $this->productMock->expects($this->once())
            ->method('getAttributes')
            ->willReturn($attributesModels);
    }

    /**
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function initializeDataProvider()
    {
        return [
            [
                'single_store' => false,
                'website_ids' => ['1' => 1, '2' => 1],
                'expected_website_ids' => ['1' => 1, '2' => 1],
                'links' => [],
                'linkTypes' => ['related', 'upsell', 'crosssell'],
                'expected_links' => [],
                'tierPrice' => [1, 2, 3],
            ],
            [
                'single_store' => false,
                'website_ids' => ['1' => 1, '2' => 0],
                'expected_website_ids' => ['1' => 1],
                'links' => [],
                'linkTypes' => ['related', 'upsell', 'crosssell'],
                'expected_links' => [],
            ],
            [
                'single_store' => false,
                'website_ids' => ['1' => 0, '2' => 0],
                'expected_website_ids' => [],
                'links' => [],
                'linkTypes' => ['related', 'upsell', 'crosssell'],
                'expected_links' => [],
            ],
            [
                'single_store' => true,
                'website_ids' => [],
                'expected_website_ids' => ['1' => 1],
                'links' => [],
                'linkTypes' => ['related', 'upsell', 'crosssell'],
                'expected_links' => [],
            ],

            // Related links
            [
                'single_store' => false,
                'website_ids' => ['1' => 1, '2' => 1],
                'expected_website_ids' => ['1' => 1, '2' => 1],
                'links' => [
                    'related' => [
                        0 => [
                            'id' => 1,
                            'thumbnail' => 'http://magento.dev/media/no-image.jpg',
                            'name' => 'Test',
                            'status' => 'Enabled',
                            'attribute_set' => 'Default',
                            'sku' => 'Test',
                            'price' => 1.00,
                            'position' => 1,
                            'record_id' => 1,
                        ],
                    ],
                ],
                'linkTypes' => ['related', 'upsell', 'crosssell'],
                'expected_links' => [
                    ['type' => 'related', 'linked_product_sku' => 'Test'],
                ],
            ],

            // Custom link
            [
                'single_store' => false,
                'website_ids' => ['1' => 1, '2' => 1],
                'expected_website_ids' => ['1' => 1, '2' => 1],
                'links' => [
                    'customlink' => [
                        0 => [
                            'id' => 4,
                            'thumbnail' => 'http://magento.dev/media/no-image.jpg',
                            'name' => 'Test Custom',
                            'status' => 'Enabled',
                            'attribute_set' => 'Default',
                            'sku' => 'Testcustom',
                            'price' => 1.00,
                            'position' => 1,
                            'record_id' => 1,
                        ],
                    ],
                ],
                'linkTypes' => ['related', 'upsell', 'crosssell', 'customlink'],
                'expected_links' => [
                    ['type' => 'customlink', 'linked_product_sku' => 'Testcustom'],
                ],
            ],

            // Both links
            [
                'single_store' => false,
                'website_ids' => ['1' => 1, '2' => 1],
                'expected_website_ids' => ['1' => 1, '2' => 1],
                'links' => [
                    'related' => [
                        0 => [
                            'id' => 1,
                            'thumbnail' => 'http://magento.dev/media/no-image.jpg',
                            'name' => 'Test',
                            'status' => 'Enabled',
                            'attribute_set' => 'Default',
                            'sku' => 'Test',
                            'price' => 1.00,
                            'position' => 1,
                            'record_id' => 1,
                        ],
                    ],
                    'customlink' => [
                        0 => [
                            'id' => 4,
                            'thumbnail' => 'http://magento.dev/media/no-image.jpg',
                            'name' => 'Test Custom',
                            'status' => 'Enabled',
                            'attribute_set' => 'Default',
                            'sku' => 'Testcustom',
                            'price' => 2.00,
                            'position' => 2,
                            'record_id' => 1,
                        ],
                    ],
                ],
                'linkTypes' => ['related', 'upsell', 'crosssell', 'customlink'],
                'expected_links' => [
                    ['type' => 'related', 'linked_product_sku' => 'Test'],
                    ['type' => 'customlink', 'linked_product_sku' => 'Testcustom'],
                ],
            ],

            // Undefined link type
            [
                'single_store' => false,
                'website_ids' => ['1' => 1, '2' => 1],
                'expected_website_ids' => ['1' => 1, '2' => 1],
                'links' => [
                    'related' => [
                        0 => [
                            'id' => 1,
                            'thumbnail' => 'http://magento.dev/media/no-image.jpg',
                            'name' => 'Test',
                            'status' => 'Enabled',
                            'attribute_set' => 'Default',
                            'sku' => 'Test',
                            'price' => 1.00,
                            'position' => 1,
                            'record_id' => 1,
                        ],
                    ],
                    'customlink' => [
                        0 => [
                            'id' => 4,
                            'thumbnail' => 'http://magento.dev/media/no-image.jpg',
                            'name' => 'Test Custom',
                            'status' => 'Enabled',
                            'attribute_set' => 'Default',
                            'sku' => 'Testcustom',
                            'price' => 2.00,
                            'position' => 2,
                            'record_id' => 1,
                        ],
                    ],
                ],
                'linkTypes' => ['related', 'upsell', 'crosssell'],
                'expected_links' => [
                    ['type' => 'related', 'linked_product_sku' => 'Test'],
                ],
            ],
        ];
    }

    /**
     * Data provider for testMergeProductOptions
     *
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function mergeProductOptionsDataProvider()
    {
        return [
            'options are not array, empty array is returned' => [
                null,
                [],
                [],
            ],
            'replacement is not array, original options are returned' => [
                ['val'],
                null,
                ['val'],
            ],
            'ids do not match, no replacement occurs' => [
                [
                    [
                        'option_id' => '3',
                        'key1' => 'val1',
                        'default_key1' => 'val2',
                        'values' => [
                            [
                                'option_type_id' => '2',
                                'key1' => 'val1',
                                'default_key1' => 'val2',
                            ],
                        ],
                    ],
                ],
                [
                    4 => [
                        'key1' => '1',
                        'values' => [3 => ['key1' => 1]],
                    ],
                ],
                [
                    [
                        'option_id' => '3',
                        'key1' => 'val1',
                        'default_key1' => 'val2',
                        'values' => [
                            [
                                'option_type_id' => '2',
                                'key1' => 'val1',
                                'default_key1' => 'val2',
                            ],
                        ],
                    ],
                ],
            ],
            'key2 is replaced, key1 is not (checkbox is not checked)' => [
                [
                    [
                        'option_id' => '5',
                        'key1' => 'val1',
                        'title' => 'val2',
                        'default_key1' => 'val3',
                        'default_title' => 'val4',
                        'values' => [
                            [
                                'option_type_id' => '2',
                                'key1' => 'val1',
                                'key2' => 'val2',
                                'default_key1' => 'val11',
                                'default_key2' => 'val22',
                            ],
                        ],
                    ],
                ],
                [
                    5 => [
                        'key1' => '0',
                        'title' => '1',
                        'values' => [2 => ['key1' => 1]],
                    ],
                ],
                [
                    [
                        'option_id' => '5',
                        'key1' => 'val1',
                        'title' => 'val4',
                        'default_key1' => 'val3',
                        'default_title' => 'val4',
                        'is_delete_store_title' => 1,
                        'values' => [
                            [
                                'option_type_id' => '2',
                                'key1' => 'val11',
                                'key2' => 'val2',
                                'default_key1' => 'val11',
                                'default_key2' => 'val22',
                            ],
                        ],
                    ],
                ],
            ],
            'key1 is replaced, key2 has no default value' => [
                [
                    [
                        'option_id' => '7',
                        'key1' => 'val1',
                        'key2' => 'val2',
                        'default_key1' => 'val3',
                        'values' => [
                            [
                                'option_type_id' => '2',
                                'key1' => 'val1',
                                'title' => 'val2',
                                'default_key1' => 'val11',
                                'default_title' => 'val22',
                            ],
                        ],
                    ],
                ],
                [
                    7 => [
                        'key1' => '1',
                        'key2' => '1',
                        'values' => [2 => ['key1' => 0, 'title' => 1]],
                    ],
                ],
                [
                    [
                        'option_id' => '7',
                        'key1' => 'val3',
                        'key2' => 'val2',
                        'default_key1' => 'val3',
                        'values' => [
                            [
                                'option_type_id' => '2',
                                'key1' => 'val1',
                                'title' => 'val22',
                                'default_key1' => 'val11',
                                'default_title' => 'val22',
                                'is_delete_store_title' => 1,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array $productOptions
     * @param array $defaultOptions
     * @param array $expectedResults
     * @dataProvider mergeProductOptionsDataProvider
     */
    public function testMergeProductOptions($productOptions, $defaultOptions, $expectedResults)
    {
        $result = $this->helper->mergeProductOptions($productOptions, $defaultOptions);
        $this->assertEquals($expectedResults, $result);
    }

    /**
     * @param array $types
     * @return array
     */
    private function assembleLinkTypes($types)
    {
        $linkTypes = [];
        $linkTypeCode = 1;

        foreach ($types as $typeName) {
            $linkType = $this->getMockForAbstractClass(ProductLinkTypeInterface::class);
            $linkType->method('getCode')->willReturn($linkTypeCode++);
            $linkType->method('getName')->willReturn($typeName);

            $linkTypes[] = $linkType;
        }

        return $linkTypes;
    }

    /**
     * @param array $links
     */
    private function assembleProductRepositoryMock($links)
    {
        $repositoryReturnMap = [];

        foreach ($links as $linkType) {
            foreach ($linkType as $link) {
                $mockLinkedProduct = $this->getMockBuilder(Product::class)
                    ->disableOriginalConstructor()
                    ->getMock();

                $mockLinkedProduct->expects($this->any())
                    ->method('getId')
                    ->willReturn($link['id']);

                $mockLinkedProduct->expects($this->any())
                    ->method('getSku')
                    ->willReturn($link['sku']);

                // Even optional arguments need to be provided for returnMapValue
                $repositoryReturnMap[] = [$link['id'], false, null, false, $mockLinkedProduct];
            }
        }

        $this->productRepositoryMock->expects($this->any())
            ->method('getById')
            ->willReturnMap($repositoryReturnMap);
    }
}
