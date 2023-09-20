<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Bundle\Test\Unit\Model;

use Magento\Bundle\Model\LinkManagement;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * Class LinkManagementTest
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class LinkManagementTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Bundle\Model\LinkManagement
     */
    protected $model;

    /**
     * @var \Magento\Catalog\Model\ProductRepository|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $productRepository;

    /**
     * @var \Magento\Catalog\Model\Product|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $product;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $linkFactory;

    /**
     * @var \Magento\Catalog\Model\Product\Type\Interceptor|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $productType;

    /**
     * @var \Magento\Bundle\Model\ResourceModel\Option\Collection|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $optionCollection;

    /**
     * @var \Magento\Bundle\Model\ResourceModel\Selection\Collection|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $selectionCollection;

    /**
     * @var \Magento\Bundle\Model\Option|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $option;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Bundle\Model\SelectionFactory
     */
    protected $bundleSelectionMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Bundle\Model\ResourceModel\BundleFactory
     */
    protected $bundleFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Bundle\Model\ResourceModel\Option\CollectionFactory
     */
    protected $optionCollectionFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManagerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $link;

    /**
     * @var int
     */
    protected $storeId = 2;

    /**
     * @var array
     */
    protected $optionIds = [1, 2, 3];

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $dataObjectHelperMock;

    /**
     * @var \Magento\Framework\EntityManager\MetadataPool|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $metadataPoolMock;

    /**
     * @var \Magento\Framework\EntityManager\EntityMetadata|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $metadataMock;

    /**
     * @var string
     */
    protected $linkField = 'product_id';

    protected function setUp(): void
    {
        $helper = new ObjectManager($this);

        $this->productRepository = $this->getMockBuilder(\Magento\Catalog\Model\ProductRepository::class)
            ->setMethods(['get'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->productType = $this->getMockBuilder(\Magento\Bundle\Model\Product\Type\Interceptor::class)
            ->setMethods(['getOptionsCollection', 'setStoreFilter', 'getSelectionsCollection', 'getOptionsIds'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->option = $this->getMockBuilder(\Magento\Bundle\Model\Option::class)
            ->setMethods(['getSelections', 'getOptionId', '__wakeup'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->optionCollection = $this->getMockBuilder(\Magento\Bundle\Model\ResourceModel\Option\Collection::class)
            ->setMethods(['appendSelections'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->selectionCollection = $this->getMockBuilder(
            \Magento\Bundle\Model\ResourceModel\Selection\Collection::class
        )->disableOriginalConstructor()->getMock();
        $this->product = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->setMethods(['getTypeInstance', 'getStoreId', 'getTypeId', '__wakeup', 'getId', 'getData'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->link = $this->getMockBuilder(\Magento\Bundle\Api\Data\LinkInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->linkFactory = $this->getMockBuilder(\Magento\Bundle\Api\Data\LinkInterfaceFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->bundleSelectionMock = $this->createPartialMock(
            \Magento\Bundle\Model\SelectionFactory::class,
            ['create']
        );
        $this->bundleFactoryMock = $this->createPartialMock(
            \Magento\Bundle\Model\ResourceModel\BundleFactory::class,
            ['create']
        );
        $this->optionCollectionFactoryMock = $this->createPartialMock(
            \Magento\Bundle\Model\ResourceModel\Option\CollectionFactory::class,
            ['create']
        );
        $this->storeManagerMock = $this->createMock(\Magento\Store\Model\StoreManagerInterface::class);
        $this->metadataPoolMock = $this->getMockBuilder(\Magento\Framework\EntityManager\MetadataPool::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->metadataMock = $this->getMockBuilder(\Magento\Framework\EntityManager\EntityMetadata::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->metadataPoolMock->expects($this->any())->method('getMetadata')
            ->with(\Magento\Catalog\Api\Data\ProductInterface::class)
            ->willReturn($this->metadataMock);

        $this->dataObjectHelperMock = $this->getMockBuilder(\Magento\Framework\Api\DataObjectHelper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->model = $helper->getObject(

            LinkManagement::class,
            [
                'productRepository' => $this->productRepository,
                'linkFactory' => $this->linkFactory,
                'bundleFactory' => $this->bundleFactoryMock,
                'bundleSelection' => $this->bundleSelectionMock,
                'optionCollection' => $this->optionCollectionFactoryMock,
                'storeManager' => $this->storeManagerMock,
                'dataObjectHelper' => $this->dataObjectHelperMock,
            ]
        );
        $refClass = new \ReflectionClass(LinkManagement::class);
        $refProperty = $refClass->getProperty('metadataPool');
        $refProperty->setAccessible(true);
        $refProperty->setValue($this->model, $this->metadataPoolMock);
    }

    public function testGetChildren()
    {
        $productSku = 'productSku';

        $this->getOptions();

        $this->productRepository->expects($this->any())->method('get')->with($this->equalTo($productSku))
            ->willReturn($this->product);

        $this->product->expects($this->once())->method('getTypeId')->willReturn('bundle');

        $this->productType->expects($this->once())->method('setStoreFilter')->with(
            $this->equalTo($this->storeId),
            $this->product
        );
        $this->productType->expects($this->once())->method('getSelectionsCollection')
            ->with($this->equalTo($this->optionIds), $this->equalTo($this->product))
            ->willReturn($this->selectionCollection);
        $this->productType->expects($this->once())->method('getOptionsIds')->with($this->equalTo($this->product))
            ->willReturn($this->optionIds);

        $this->optionCollection->expects($this->once())->method('appendSelections')
            ->with($this->equalTo($this->selectionCollection))
            ->willReturn([$this->option]);

        $this->option->expects($this->any())->method('getSelections')->willReturn([$this->product]);
        $this->product->expects($this->any())->method('getData')->willReturn([]);

        $this->dataObjectHelperMock->expects($this->once())
            ->method('populateWithArray')
            ->with($this->link, $this->anything(), \Magento\Bundle\Api\Data\LinkInterface::class)
            ->willReturnSelf();
        $this->link->expects($this->once())->method('setIsDefault')->willReturnSelf();
        $this->link->expects($this->once())->method('setQty')->willReturnSelf();
        $this->link->expects($this->once())->method('setCanChangeQuantity')->willReturnSelf();
        $this->link->expects($this->once())->method('setPrice')->willReturnSelf();
        $this->link->expects($this->once())->method('setPriceType')->willReturnSelf();
        $this->link->expects($this->once())->method('setId')->willReturnSelf();
        $this->linkFactory->expects($this->once())->method('create')->willReturn($this->link);

        $this->assertEquals([$this->link], $this->model->getChildren($productSku));
    }

    public function testGetChildrenWithOptionId()
    {
        $productSku = 'productSku';

        $this->getOptions();

        $this->productRepository->expects($this->any())->method('get')->with($this->equalTo($productSku))
            ->willReturn($this->product);

        $this->product->expects($this->once())->method('getTypeId')->willReturn('bundle');

        $this->productType->expects($this->once())->method('setStoreFilter')->with(
            $this->equalTo($this->storeId),
            $this->product
        );
        $this->productType->expects($this->once())->method('getSelectionsCollection')
            ->with($this->equalTo($this->optionIds), $this->equalTo($this->product))
            ->willReturn($this->selectionCollection);
        $this->productType->expects($this->once())->method('getOptionsIds')->with($this->equalTo($this->product))
            ->willReturn($this->optionIds);

        $this->optionCollection->expects($this->once())->method('appendSelections')
            ->with($this->equalTo($this->selectionCollection))
            ->willReturn([$this->option]);

        $this->option->expects($this->any())->method('getOptionId')->willReturn(10);
        $this->option->expects($this->once())->method('getSelections')->willReturn([1, 2]);

        $this->dataObjectHelperMock->expects($this->never())->method('populateWithArray');

        $this->assertEquals([], $this->model->getChildren($productSku, 1));
    }

    /**
     */
    public function testGetChildrenException()
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);

        $productSku = 'productSku';

        $this->productRepository->expects($this->once())->method('get')->with($this->equalTo($productSku))
            ->willReturn($this->product);

        $this->product->expects($this->once())->method('getTypeId')->willReturn('simple');

        $this->assertEquals([$this->link], $this->model->getChildren($productSku));
    }

    /**
     */
    public function testAddChildToNotBundleProduct()
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);

        $productLink = $this->createMock(\Magento\Bundle\Api\Data\LinkInterface::class);
        $productLink->expects($this->any())->method('getOptionId')->willReturn(1);

        $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productMock->expects($this->once())->method('getTypeId')->willReturn(
            \Magento\Catalog\Model\Product\Type::TYPE_SIMPLE
        );
        $this->model->addChild($productMock, 1, $productLink);
    }

    /**
     */
    public function testAddChildNonExistingOption()
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);

        $productLink = $this->createMock(\Magento\Bundle\Api\Data\LinkInterface::class);
        $productLink->expects($this->any())->method('getOptionId')->willReturn(1);

        $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productMock->expects($this->once())->method('getTypeId')->willReturn(
            \Magento\Catalog\Model\Product\Type::TYPE_BUNDLE
        );

        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $this->storeManagerMock->expects($this->any())->method('getStore')->willReturn($store);
        $store->expects($this->any())->method('getId')->willReturn(0);

        $emptyOption = $this->getMockBuilder(\Magento\Bundle\Model\Option::class)->disableOriginalConstructor()
            ->setMethods(['getId', '__wakeup'])
            ->getMock();
        $emptyOption->expects($this->once())
            ->method('getId')
            ->willReturn(null);

        $optionsCollectionMock = $this->createMock(\Magento\Bundle\Model\ResourceModel\Option\Collection::class);
        $optionsCollectionMock->expects($this->once())
            ->method('setIdFilter')
            ->with($this->equalTo(1))
            ->willReturnSelf();
        $optionsCollectionMock->expects($this->once())
            ->method('getFirstItem')
           ->willReturn($emptyOption);

        $this->optionCollectionFactoryMock->expects($this->any())->method('create')->willReturn(
            $optionsCollectionMock
        );
        $this->model->addChild($productMock, 1, $productLink);
    }

    /**
     */
    public function testAddChildLinkedProductIsComposite()
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);
        $this->expectExceptionMessage('The bundle product can\'t contain another composite product.');

        $productLink = $this->createMock(\Magento\Bundle\Api\Data\LinkInterface::class);
        $productLink->expects($this->any())->method('getSku')->willReturn('linked_product_sku');
        $productLink->expects($this->any())->method('getOptionId')->willReturn(1);

        $this->metadataMock->expects($this->once())->method('getLinkField')->willReturn($this->linkField);
        $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productMock->expects($this->once())->method('getTypeId')->willReturn(
            \Magento\Catalog\Model\Product\Type::TYPE_BUNDLE
        );
        $productMock->expects($this->any())
            ->method('getData')
            ->with($this->linkField)
            ->willReturn($this->linkField);

        $linkedProductMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $linkedProductMock->expects($this->any())->method('getId')->willReturn(13);
        $linkedProductMock->expects($this->once())->method('isComposite')->willReturn(true);
        $this->productRepository
            ->expects($this->once())
            ->method('get')
            ->with('linked_product_sku')
            ->willReturn($linkedProductMock);

        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $this->storeManagerMock->expects($this->any())->method('getStore')->willReturn($store);
        $store->expects($this->any())->method('getId')->willReturn(0);

        $option = $this->getMockBuilder(\Magento\Bundle\Model\Option::class)->disableOriginalConstructor()
            ->setMethods(['getId', '__wakeup'])
            ->getMock();
        $option->expects($this->once())->method('getId')->willReturn(1);

        $optionsCollectionMock = $this->createMock(\Magento\Bundle\Model\ResourceModel\Option\Collection::class);
        $optionsCollectionMock->expects($this->once())
            ->method('setIdFilter')
            ->with($this->equalTo('1'))
            ->willReturnSelf();
        $optionsCollectionMock->expects($this->once())
            ->method('getFirstItem')
            ->willReturn($option);
        $this->optionCollectionFactoryMock->expects($this->any())->method('create')->willReturn(
            $optionsCollectionMock
        );

        $bundle = $this->createMock(\Magento\Bundle\Model\ResourceModel\Bundle::class);
        $bundle->expects($this->once())->method('getSelectionsData')->with($this->linkField)->willReturn([]);
        $this->bundleFactoryMock->expects($this->once())->method('create')->willReturn($bundle);
        $this->model->addChild($productMock, 1, $productLink);
    }

    /**
     */
    public function testAddChildProductAlreadyExistsInOption()
    {
        $this->expectException(\Magento\Framework\Exception\CouldNotSaveException::class);

        $productLink = $this->getMockBuilder(\Magento\Bundle\Api\Data\LinkInterface::class)
            ->setMethods(['getSku', 'getOptionId', 'getSelectionId'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $productLink->expects($this->any())->method('getSku')->willReturn('linked_product_sku');
        $productLink->expects($this->any())->method('getOptionId')->willReturn(1);
        $productLink->expects($this->any())->method('getSelectionId')->willReturn(1);

        $this->metadataMock->expects($this->once())->method('getLinkField')->willReturn($this->linkField);
        $productMock = $this->createPartialMock(
            \Magento\Catalog\Model\Product::class,
            ['getTypeId', 'getCopyFromView', 'getData', 'getTypeInstance', 'getSku']
        );
        $productMock->expects($this->once())->method('getTypeId')->willReturn(
            \Magento\Catalog\Model\Product\Type::TYPE_BUNDLE
        );

        $productMock->expects($this->any())
            ->method('getData')
            ->with($this->linkField)
            ->willReturn($this->linkField);
        $productMock->expects($this->any())->method('getCopyFromView')->willReturn(false);

        $linkedProductMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $linkedProductMock->expects($this->any())->method('getEntityId')->willReturn(13);
        $linkedProductMock->expects($this->once())->method('isComposite')->willReturn(false);
        $this->productRepository
            ->expects($this->once())
            ->method('get')
            ->with('linked_product_sku')
            ->willReturn($linkedProductMock);

        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $this->storeManagerMock->expects($this->any())->method('getStore')->willReturn($store);
        $store->expects($this->any())->method('getId')->willReturn(0);

        $option = $this->getMockBuilder(\Magento\Bundle\Model\Option::class)->disableOriginalConstructor()
            ->setMethods(['getId', '__wakeup'])
            ->getMock();
        $option->expects($this->once())->method('getId')->willReturn(1);

        $optionsCollectionMock = $this->createMock(\Magento\Bundle\Model\ResourceModel\Option\Collection::class);
        $optionsCollectionMock->expects($this->once())
            ->method('setIdFilter')
            ->with($this->equalTo(1))
            ->willReturnSelf();
        $optionsCollectionMock->expects($this->once())
            ->method('getFirstItem')
            ->willReturn($option);
        $this->optionCollectionFactoryMock->expects($this->any())->method('create')->willReturn(
            $optionsCollectionMock
        );

        $selections = [
            ['option_id' => 1, 'product_id' => 12, 'parent_product_id' => 'product_id'],
            ['option_id' => 1, 'product_id' => 13, 'parent_product_id' => 'product_id'],
        ];
        $bundle = $this->createMock(\Magento\Bundle\Model\ResourceModel\Bundle::class);
        $bundle->expects($this->once())->method('getSelectionsData')
            ->with($this->linkField)
            ->willReturn($selections);
        $this->bundleFactoryMock->expects($this->once())->method('create')->willReturn($bundle);
        $this->model->addChild($productMock, 1, $productLink);
    }

    /**
     */
    public function testAddChildCouldNotSave()
    {
        $this->expectException(\Magento\Framework\Exception\CouldNotSaveException::class);

        $productLink = $this->getMockBuilder(\Magento\Bundle\Api\Data\LinkInterface::class)
            ->setMethods(['getSku', 'getOptionId', 'getSelectionId'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $productLink->expects($this->any())->method('getSku')->willReturn('linked_product_sku');
        $productLink->expects($this->any())->method('getOptionId')->willReturn(1);
        $productLink->expects($this->any())->method('getSelectionId')->willReturn(1);

        $this->metadataMock->expects($this->once())->method('getLinkField')->willReturn($this->linkField);
        $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productMock->expects($this->once())->method('getTypeId')->willReturn(
            \Magento\Catalog\Model\Product\Type::TYPE_BUNDLE
        );
        $productMock->expects($this->any())
            ->method('getData')
            ->with($this->linkField)
            ->willReturn($this->linkField);

        $linkedProductMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $linkedProductMock->expects($this->any())->method('getId')->willReturn(13);
        $linkedProductMock->expects($this->once())->method('isComposite')->willReturn(false);
        $this->productRepository
            ->expects($this->once())
            ->method('get')
            ->with('linked_product_sku')
            ->willReturn($linkedProductMock);

        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $this->storeManagerMock->expects($this->any())->method('getStore')->willReturn($store);
        $store->expects($this->any())->method('getId')->willReturn(0);

        $option = $this->getMockBuilder(\Magento\Bundle\Model\Option::class)->disableOriginalConstructor()
            ->setMethods(['getId', '__wakeup'])
            ->getMock();
        $option->expects($this->once())->method('getId')->willReturn(1);

        $optionsCollectionMock = $this->createMock(\Magento\Bundle\Model\ResourceModel\Option\Collection::class);
        $optionsCollectionMock->expects($this->once())
            ->method('setIdFilter')
            ->with($this->equalTo(1))
            ->willReturnSelf();
        $optionsCollectionMock->expects($this->once())
            ->method('getFirstItem')
            ->willReturn($option);
        $this->optionCollectionFactoryMock->expects($this->any())->method('create')->willReturn(
            $optionsCollectionMock
        );

        $selections = [
            ['option_id' => 1, 'product_id' => 11],
            ['option_id' => 1, 'product_id' => 12],
        ];
        $bundle = $this->createMock(\Magento\Bundle\Model\ResourceModel\Bundle::class);
        $bundle->expects($this->once())->method('getSelectionsData')
            ->with($this->linkField)
            ->willReturn($selections);
        $this->bundleFactoryMock->expects($this->once())->method('create')->willReturn($bundle);

        $selection = $this->createPartialMock(\Magento\Bundle\Model\Selection::class, ['save']);
        $selection->expects($this->once())->method('save')
            ->willReturnCallback(
                
                    function () {
                        throw new \Exception('message');
                    }
                
            );
        $this->bundleSelectionMock->expects($this->once())->method('create')->willReturn($selection);
        $this->model->addChild($productMock, 1, $productLink);
    }

    public function testAddChild()
    {
        $productLink = $this->getMockBuilder(\Magento\Bundle\Api\Data\LinkInterface::class)
            ->setMethods(['getSku', 'getOptionId', 'getSelectionId'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $productLink->expects($this->any())->method('getSku')->willReturn('linked_product_sku');
        $productLink->expects($this->any())->method('getOptionId')->willReturn(1);
        $productLink->expects($this->any())->method('getSelectionId')->willReturn(1);

        $this->metadataMock->expects($this->once())->method('getLinkField')->willReturn($this->linkField);
        $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productMock->expects($this->once())->method('getTypeId')->willReturn(
            \Magento\Catalog\Model\Product\Type::TYPE_BUNDLE
        );
        $productMock->expects($this->any())
            ->method('getData')
            ->with($this->linkField)
            ->willReturn($this->linkField);

        $linkedProductMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $linkedProductMock->expects($this->any())->method('getId')->willReturn(13);
        $linkedProductMock->expects($this->once())->method('isComposite')->willReturn(false);
        $this->productRepository
            ->expects($this->once())
            ->method('get')
            ->with('linked_product_sku')
            ->willReturn($linkedProductMock);

        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $this->storeManagerMock->expects($this->any())->method('getStore')->willReturn($store);
        $store->expects($this->any())->method('getId')->willReturn(0);

        $option = $this->getMockBuilder(\Magento\Bundle\Model\Option::class)->disableOriginalConstructor()
            ->setMethods(['getId', '__wakeup'])
            ->getMock();
        $option->expects($this->once())->method('getId')->willReturn(1);

        $optionsCollectionMock = $this->createMock(\Magento\Bundle\Model\ResourceModel\Option\Collection::class);
        $optionsCollectionMock->expects($this->once())
            ->method('setIdFilter')
            ->with($this->equalTo(1))
            ->willReturnSelf();
        $optionsCollectionMock->expects($this->once())
            ->method('getFirstItem')
            ->willReturn($option);
        $this->optionCollectionFactoryMock->expects($this->any())->method('create')->willReturn(
            $optionsCollectionMock
        );

        $selections = [
            ['option_id' => 1, 'product_id' => 11],
            ['option_id' => 1, 'product_id' => 12],
        ];
        $bundle = $this->createMock(\Magento\Bundle\Model\ResourceModel\Bundle::class);
        $bundle->expects($this->once())->method('getSelectionsData')
            ->with($this->linkField)
            ->willReturn($selections);
        $this->bundleFactoryMock->expects($this->once())->method('create')->willReturn($bundle);

        $selection = $this->createPartialMock(\Magento\Bundle\Model\Selection::class, ['save', 'getId']);
        $selection->expects($this->once())->method('save');
        $selection->expects($this->once())->method('getId')->willReturn(42);
        $this->bundleSelectionMock->expects($this->once())->method('create')->willReturn($selection);
        $result = $this->model->addChild($productMock, 1, $productLink);
        $this->assertEquals(42, $result);
    }

    public function testSaveChild()
    {
        $id = 12;
        $optionId = 1;
        $position = 3;
        $qty = 2;
        $priceType = 1;
        $price = 10.5;
        $canChangeQuantity = true;
        $isDefault = true;
        $linkProductId = 45;
        $parentProductId = 32;
        $bundleProductSku = 'bundleProductSku';

        $productLink = $this->getMockBuilder(\Magento\Bundle\Api\Data\LinkInterface::class)
            ->setMethods(['getSku', 'getOptionId', 'getSelectionId'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $productLink->expects($this->any())->method('getSku')->willReturn('linked_product_sku');
        $productLink->expects($this->any())->method('getId')->willReturn($id);
        $productLink->expects($this->any())->method('getOptionId')->willReturn($optionId);
        $productLink->expects($this->any())->method('getPosition')->willReturn($position);
        $productLink->expects($this->any())->method('getQty')->willReturn($qty);
        $productLink->expects($this->any())->method('getPriceType')->willReturn($priceType);
        $productLink->expects($this->any())->method('getPrice')->willReturn($price);
        $productLink->expects($this->any())->method('getCanChangeQuantity')
            ->willReturn($canChangeQuantity);
        $productLink->expects($this->any())->method('getIsDefault')->willReturn($isDefault);
        $productLink->expects($this->any())->method('getSelectionId')->willReturn($optionId);

        $this->metadataMock->expects($this->once())->method('getLinkField')->willReturn($this->linkField);
        $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productMock->expects($this->once())->method('getTypeId')->willReturn(
            \Magento\Catalog\Model\Product\Type::TYPE_BUNDLE
        );
        $productMock->expects($this->any())
            ->method('getData')
            ->with($this->linkField)
            ->willReturn($parentProductId);

        $linkedProductMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $linkedProductMock->expects($this->any())->method('getId')->willReturn($linkProductId);
        $linkedProductMock->expects($this->once())->method('isComposite')->willReturn(false);
        $this->productRepository
            ->expects($this->at(0))
            ->method('get')
            ->with($bundleProductSku)
            ->willReturn($productMock);
        $this->productRepository
            ->expects($this->at(1))
            ->method('get')
            ->with('linked_product_sku')
            ->willReturn($linkedProductMock);

        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $this->storeManagerMock->expects($this->any())->method('getStore')->willReturn($store);
        $store->expects($this->any())->method('getId')->willReturn(0);

        $selection = $this->createPartialMock(\Magento\Bundle\Model\Selection::class, [
                'save',
                'getId',
                'load',
                'setProductId',
                'setParentProductId',
                'setOptionId',
                'setPosition',
                'setSelectionQty',
                'setSelectionPriceType',
                'setSelectionPriceValue',
                'setSelectionCanChangeQty',
                'setIsDefault'
            ]);
        $selection->expects($this->once())->method('save');
        $selection->expects($this->once())->method('load')->with($id)->willReturnSelf();
        $selection->expects($this->any())->method('getId')->willReturn($id);
        $selection->expects($this->once())->method('setProductId')->with($linkProductId);
        $selection->expects($this->once())->method('setParentProductId')->with($parentProductId);
        $selection->expects($this->once())->method('setOptionId')->with($optionId);
        $selection->expects($this->once())->method('setPosition')->with($position);
        $selection->expects($this->once())->method('setSelectionQty')->with($qty);
        $selection->expects($this->once())->method('setSelectionPriceType')->with($priceType);
        $selection->expects($this->once())->method('setSelectionPriceValue')->with($price);
        $selection->expects($this->once())->method('setSelectionCanChangeQty')->with($canChangeQuantity);
        $selection->expects($this->once())->method('setIsDefault')->with($isDefault);

        $this->bundleSelectionMock->expects($this->once())->method('create')->willReturn($selection);
        $this->assertTrue($this->model->saveChild($bundleProductSku, $productLink));
    }

    /**
     */
    public function testSaveChildFailedToSave()
    {
        $this->expectException(\Magento\Framework\Exception\CouldNotSaveException::class);

        $id = 12;
        $linkProductId = 45;
        $parentProductId = 32;

        $productLink = $this->getMockBuilder(\Magento\Bundle\Api\Data\LinkInterface::class)
            ->setMethods(['getSku', 'getOptionId', 'getSelectionId'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $productLink->expects($this->any())->method('getSku')->willReturn('linked_product_sku');
        $productLink->expects($this->any())->method('getId')->willReturn($id);
        $productLink->expects($this->any())->method('getSelectionId')->willReturn(1);
        $bundleProductSku = 'bundleProductSku';

        $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productMock->expects($this->once())->method('getTypeId')->willReturn(
            \Magento\Catalog\Model\Product\Type::TYPE_BUNDLE
        );
        $productMock->expects($this->any())->method('getId')->willReturn($parentProductId);

        $linkedProductMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $linkedProductMock->expects($this->any())->method('getId')->willReturn($linkProductId);
        $linkedProductMock->expects($this->once())->method('isComposite')->willReturn(false);
        $this->productRepository
            ->expects($this->at(0))
            ->method('get')
            ->with($bundleProductSku)
            ->willReturn($productMock);
        $this->productRepository
            ->expects($this->at(1))
            ->method('get')
            ->with('linked_product_sku')
            ->willReturn($linkedProductMock);

        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $this->storeManagerMock->expects($this->any())->method('getStore')->willReturn($store);
        $store->expects($this->any())->method('getId')->willReturn(0);

        $selection = $this->createPartialMock(\Magento\Bundle\Model\Selection::class, [
                'save',
                'getId',
                'load',
                'setProductId',
                'setParentProductId',
                'setSelectionId',
                'setOptionId',
                'setPosition',
                'setSelectionQty',
                'setSelectionPriceType',
                'setSelectionPriceValue',
                'setSelectionCanChangeQty',
                'setIsDefault'
            ]);
        $mockException = $this->createMock(\Exception::class);
        $selection->expects($this->once())->method('save')->will($this->throwException($mockException));
        $selection->expects($this->once())->method('load')->with($id)->willReturnSelf();
        $selection->expects($this->any())->method('getId')->willReturn($id);
        $selection->expects($this->once())->method('setProductId')->with($linkProductId);

        $this->bundleSelectionMock->expects($this->once())->method('create')->willReturn($selection);
        $this->model->saveChild($bundleProductSku, $productLink);
    }

    /**
     */
    public function testSaveChildWithoutId()
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);

        $bundleProductSku = "bundleSku";
        $linkedProductSku = 'simple';
        $productLink = $this->createMock(\Magento\Bundle\Api\Data\LinkInterface::class);
        $productLink->expects($this->any())->method('getId')->willReturn(null);
        $productLink->expects($this->any())->method('getSku')->willReturn($linkedProductSku);

        $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productMock->expects($this->once())->method('getTypeId')->willReturn(
            \Magento\Catalog\Model\Product\Type::TYPE_BUNDLE
        );

        $linkedProductMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $linkedProductMock->expects($this->once())->method('isComposite')->willReturn(false);
        $this->productRepository
            ->expects($this->at(0))
            ->method('get')
            ->with($bundleProductSku)
            ->willReturn($productMock);
        $this->productRepository
            ->expects($this->at(1))
            ->method('get')
            ->with($linkedProductSku)
            ->willReturn($linkedProductMock);

        $this->model->saveChild($bundleProductSku, $productLink);
    }

    /**
     */
    public function testSaveChildWithInvalidId()
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);
        $this->expectExceptionMessage('The product link with the "12345" ID field wasn\'t found. Verify the ID and try again.');

        $id = 12345;
        $linkedProductSku = 'simple';
        $bundleProductSku = "bundleProductSku";
        $productLink = $this->createMock(\Magento\Bundle\Api\Data\LinkInterface::class);
        $productLink->expects($this->any())->method('getId')->willReturn($id);
        $productLink->expects($this->any())->method('getSku')->willReturn($linkedProductSku);

        $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productMock->expects($this->once())->method('getTypeId')->willReturn(
            \Magento\Catalog\Model\Product\Type::TYPE_BUNDLE
        );

        $linkedProductMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $linkedProductMock->expects($this->once())->method('isComposite')->willReturn(false);
        $this->productRepository
            ->expects($this->at(0))
            ->method('get')
            ->with($bundleProductSku)
            ->willReturn($productMock);
        $this->productRepository
            ->expects($this->at(1))
            ->method('get')
            ->with($linkedProductSku)
            ->willReturn($linkedProductMock);

        $selection = $this->createPartialMock(\Magento\Bundle\Model\Selection::class, [
                'getId',
                'load',
            ]);
        $selection->expects($this->once())->method('load')->with($id)->willReturnSelf();
        $selection->expects($this->any())->method('getId')->willReturn(null);

        $this->bundleSelectionMock->expects($this->once())->method('create')->willReturn($selection);

        $this->model->saveChild($bundleProductSku, $productLink);
    }

    /**
     */
    public function testSaveChildWithCompositeProductLink()
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);

        $bundleProductSku = "bundleProductSku";
        $id = 12;
        $linkedProductSku = 'simple';
        $productLink = $this->createMock(\Magento\Bundle\Api\Data\LinkInterface::class);
        $productLink->expects($this->any())->method('getId')->willReturn($id);
        $productLink->expects($this->any())->method('getSku')->willReturn($linkedProductSku);

        $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productMock->expects($this->once())->method('getTypeId')->willReturn(
            \Magento\Catalog\Model\Product\Type::TYPE_BUNDLE
        );

        $linkedProductMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $linkedProductMock->expects($this->once())->method('isComposite')->willReturn(true);
        $this->productRepository
            ->expects($this->at(0))
            ->method('get')
            ->with($bundleProductSku)
            ->willReturn($productMock);
        $this->productRepository
            ->expects($this->at(1))
            ->method('get')
            ->with($linkedProductSku)
            ->willReturn($linkedProductMock);

        $this->model->saveChild($bundleProductSku, $productLink);
    }

    /**
     */
    public function testSaveChildWithSimpleProduct()
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);

        $id = 12;
        $linkedProductSku = 'simple';
        $bundleProductSku = "bundleProductSku";

        $productLink = $this->createMock(\Magento\Bundle\Api\Data\LinkInterface::class);
        $productLink->expects($this->any())->method('getId')->willReturn($id);
        $productLink->expects($this->any())->method('getSku')->willReturn($linkedProductSku);

        $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productMock->expects($this->once())->method('getTypeId')->willReturn(
            \Magento\Catalog\Model\Product\Type::TYPE_SIMPLE
        );

        $this->productRepository->expects($this->once())->method('get')->with($bundleProductSku)
            ->willReturn($productMock);

        $this->model->saveChild($bundleProductSku, $productLink);
    }

    public function testRemoveChild()
    {
        $this->productRepository->expects($this->any())->method('get')->willReturn($this->product);
        $bundle = $this->createMock(\Magento\Bundle\Model\ResourceModel\Bundle::class);
        $this->bundleFactoryMock->expects($this->once())->method('create')->willReturn($bundle);
        $productSku = 'productSku';
        $optionId = 1;
        $productId = 1;
        $childSku = 'childSku';

        $this->product
            ->expects($this->any())
            ->method('getTypeId')
            ->willReturn(\Magento\Catalog\Model\Product\Type::TYPE_BUNDLE);

        $this->getRemoveOptions();

        $selection = $this->getMockBuilder(\Magento\Bundle\Model\Selection::class)
            ->setMethods(['getSku', 'getOptionId', 'getSelectionId', 'getProductId', '__wakeup'])
            ->disableOriginalConstructor()
            ->getMock();
        $selection->expects($this->any())->method('getSku')->willReturn($childSku);
        $selection->expects($this->any())->method('getOptionId')->willReturn($optionId);
        $selection->expects($this->any())->method('getSelectionId')->willReturn(55);
        $selection->expects($this->any())->method('getProductId')->willReturn($productId);

        $this->option->expects($this->any())->method('getSelections')->willReturn([$selection]);
        $this->metadataMock->expects($this->any())->method('getLinkField')->willReturn($this->linkField);
        $this->product->expects($this->any())
            ->method('getData')
            ->with($this->linkField)
            ->willReturn(3);

        $bundle->expects($this->once())->method('dropAllUnneededSelections')->with(3, []);
        $bundle->expects($this->once())->method('removeProductRelations')->with(3, [$productId]);
        //Params come in lowercase to method
        $this->assertTrue($this->model->removeChild($productSku, $optionId, $childSku));
    }

    /**
     */
    public function testRemoveChildForbidden()
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);

        $this->productRepository->expects($this->any())->method('get')->willReturn($this->product);
        $productSku = 'productSku';
        $optionId = 1;
        $childSku = 'childSku';
        $this->product
            ->expects($this->any())
            ->method('getTypeId')
            ->willReturn(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE);
        $this->model->removeChild($productSku, $optionId, $childSku);
    }

    /**
     */
    public function testRemoveChildInvalidOptionId()
    {
        $this->expectException(\Magento\Framework\Exception\NoSuchEntityException::class);

        $this->productRepository->expects($this->any())->method('get')->willReturn($this->product);
        $productSku = 'productSku';
        $optionId = 1;
        $childSku = 'childSku';

        $this->product
            ->expects($this->any())
            ->method('getTypeId')
            ->willReturn(\Magento\Catalog\Model\Product\Type::TYPE_BUNDLE);

        $this->getRemoveOptions();

        $selection = $this->getMockBuilder(\Magento\Bundle\Model\Selection::class)
            ->setMethods(['getSku', 'getOptionId', 'getSelectionId', 'getProductId', '__wakeup'])
            ->disableOriginalConstructor()
            ->getMock();
        $selection->expects($this->any())->method('getSku')->willReturn($childSku);
        $selection->expects($this->any())->method('getOptionId')->willReturn($optionId + 1);
        $selection->expects($this->any())->method('getSelectionId')->willReturn(55);
        $selection->expects($this->any())->method('getProductId')->willReturn(1);

        $this->option->expects($this->any())->method('getSelections')->willReturn([$selection]);
        $this->model->removeChild($productSku, $optionId, $childSku);
    }

    /**
     */
    public function testRemoveChildInvalidChildSku()
    {
        $this->expectException(\Magento\Framework\Exception\NoSuchEntityException::class);

        $this->productRepository->expects($this->any())->method('get')->willReturn($this->product);
        $productSku = 'productSku';
        $optionId = 1;
        $childSku = 'childSku';

        $this->product
            ->expects($this->any())
            ->method('getTypeId')
            ->willReturn(\Magento\Catalog\Model\Product\Type::TYPE_BUNDLE);

        $this->getRemoveOptions();

        $selection = $this->getMockBuilder(\Magento\Bundle\Model\Selection::class)
            ->setMethods(['getSku', 'getOptionId', 'getSelectionId', 'getProductId', '__wakeup'])
            ->disableOriginalConstructor()
            ->getMock();
        $selection->expects($this->any())->method('getSku')->willReturn($childSku . '_invalid');
        $selection->expects($this->any())->method('getOptionId')->willReturn($optionId);
        $selection->expects($this->any())->method('getSelectionId')->willReturn(55);
        $selection->expects($this->any())->method('getProductId')->willReturn(1);

        $this->option->expects($this->any())->method('getSelections')->willReturn([$selection]);
        $this->model->removeChild($productSku, $optionId, $childSku);
    }

    private function getOptions()
    {
        $this->product->expects($this->any())->method('getTypeInstance')->willReturn($this->productType);
        $this->product->expects($this->once())->method('getStoreId')->willReturn($this->storeId);
        $this->productType->expects($this->once())->method('setStoreFilter')
            ->with($this->equalTo($this->storeId), $this->equalTo($this->product));

        $this->productType->expects($this->once())->method('getOptionsCollection')
            ->with($this->equalTo($this->product))
            ->willReturn($this->optionCollection);
    }

    public function getRemoveOptions()
    {
        $this->product->expects($this->any())->method('getTypeInstance')->willReturn($this->productType);
        $this->product->expects($this->once())->method('getStoreId')->willReturn(1);

        $this->productType->expects($this->once())->method('setStoreFilter');
        $this->productType->expects($this->once())->method('getOptionsCollection')
            ->with($this->equalTo($this->product))
            ->willReturn($this->optionCollection);

        $this->productType->expects($this->once())->method('getOptionsIds')->with($this->equalTo($this->product))
            ->willReturn([1, 2, 3]);

        $this->productType->expects($this->once())->method('getSelectionsCollection')
            ->willReturn([]);

        $this->optionCollection->expects($this->any())->method('appendSelections')
            ->with($this->equalTo([]), true)
            ->willReturn([$this->option]);
    }
}
