<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CatalogUrlRewrite\Test\Unit\Model\Category\Plugin\Store;

use Magento\Catalog\Model\ResourceModel\Category\Collection as CategortCollection;
use Magento\CatalogUrlRewrite\Model\Category\Plugin\Store\View as StoreViewPlugin;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\Model\AbstractModel;
use Magento\Store\Model\ResourceModel\Store;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ProductFactory;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\Product;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ViewTest extends TestCase
{
    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var StoreViewPlugin
     */
    private $plugin;

    /**
     * @var AbstractModel|MockObject
     */
    private $abstractModelMock;

    /**
     * @var Store|MockObject
     */
    private $subjectMock;

    /**
     * @var UrlPersistInterface|MockObject
     */
    private $urlPersistMock;

    /**
     * @var CategoryFactory|MockObject
     */
    private $categoryFactoryMock;

    /**
     * @var ProductFactory|MockObject
     */
    private $productFactoryMock;

    /**
     * @var CategoryUrlRewriteGenerator|MockObject
     */
    private $categoryUrlRewriteGeneratorMock;

    /**
     * @var ProductUrlRewriteGenerator|MockObject
     */
    private $productUrlRewriteGeneratorMock;

    /**
     * @var Category|MockObject
     */
    private $categoryMock;

    /**
     * @var ProductCollection|MockObject
     */
    private $productCollectionMock;

    /**
     * @var Product|MockObject
     */
    private $productMock;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->abstractModelMock = $this->getMockBuilder(AbstractModel::class)
            ->disableOriginalConstructor()
            ->setMethods(['isObjectNew'])
            ->getMockForAbstractClass();
        $this->subjectMock = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->urlPersistMock = $this->getMockBuilder(UrlPersistInterface::class)
            ->setMethods(['deleteByData'])
            ->getMockForAbstractClass();
        $this->categoryMock = $this->getMockBuilder(Category::class)
            ->disableOriginalConstructor()
            ->setMethods(['getCategories'])
            ->getMock();
        $this->categoryFactoryMock = $this->getMockBuilder(CategoryFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->productFactoryMock = $this->getMockBuilder(ProductFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->categoryUrlRewriteGeneratorMock = $this->getMockBuilder(CategoryUrlRewriteGenerator::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->productUrlRewriteGeneratorMock = $this->getMockBuilder(ProductUrlRewriteGenerator::class)
            ->disableOriginalConstructor()
            ->setMethods(['generate'])
            ->getMock();
        $this->productCollectionMock = $this->getMockBuilder(ProductCollection::class)
            ->disableOriginalConstructor()
            ->setMethods(['addCategoryIds', 'addAttributeToSelect', 'getIterator', 'addStoreFilter'])
            ->getMock();
        $this->productMock = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->setMethods(['getCollection'])
            ->getMock();
        $this->plugin = $this->objectManager->getObject(
            StoreViewPlugin::class,
            [
                'urlPersist' => $this->urlPersistMock,
                'categoryFactory' => $this->categoryFactoryMock,
                'productFactory' => $this->productFactoryMock,
                'categoryUrlRewriteGenerator' => $this->categoryUrlRewriteGeneratorMock,
                'productUrlRewriteGenerator' => $this->productUrlRewriteGeneratorMock
            ]
        );
    }

    public function testAfterSave()
    {
        $origStoreMock = $this->getMockBuilder(\Magento\Store\Model\Store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $reflectionStore = new \ReflectionClass($this->plugin);
        $origStore = $reflectionStore->getProperty('origStore');
        $origStore->setAccessible(true);
        $origStore->setValue($this->plugin, $origStoreMock);
        $origStoreMock->expects($this->atLeastOnce())
            ->method('isObjectNew')
            ->willReturn(true);

        $this->abstractModelMock->expects($this->any())
            ->method('isObjectNew')
            ->willReturn(true);
        $categoryCollection = $this->getMockBuilder(CategortCollection::class)
            ->disableOriginalConstructor()
            ->setMethods(['getIterator'])
            ->getMock();
        $categoryCollection->expects($this->any())
            ->method('getIterator')
            ->willReturn(new \ArrayIterator([]));
        $this->categoryMock->expects($this->once())
            ->method('getCategories')
            ->willReturn($categoryCollection);
        $this->categoryFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->categoryMock);
        $this->productFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->productMock);
        $this->productMock->expects($this->once())
            ->method('getCollection')
            ->willReturn($this->productCollectionMock);
        $this->productCollectionMock->expects($this->once())
            ->method('addCategoryIds')
            ->willReturn($this->productCollectionMock);
        $this->productCollectionMock->expects($this->once())
            ->method('addAttributeToSelect')
            ->willReturn($this->productCollectionMock);
        $this->productCollectionMock->expects($this->once())
            ->method('addStoreFilter')
            ->willReturn($this->productCollectionMock);
        $iterator = new \ArrayIterator([$this->productMock]);
        $this->productCollectionMock->expects($this->once())
            ->method('getIterator')
            ->willReturn($iterator);
        $this->productUrlRewriteGeneratorMock->expects($this->once())
            ->method('generate')
            ->with($this->productMock)
            ->willReturn([]);

        $this->assertSame(
            $this->subjectMock,
            $this->plugin->afterSave($this->subjectMock, $this->subjectMock, $this->abstractModelMock)
        );
    }

    public function testAfterDelete()
    {
        $this->urlPersistMock->expects($this->once())
            ->method('deleteByData');
        $this->assertSame(
            $this->subjectMock,
            $this->plugin->afterDelete($this->subjectMock, $this->subjectMock, $this->abstractModelMock)
        );
    }
}
