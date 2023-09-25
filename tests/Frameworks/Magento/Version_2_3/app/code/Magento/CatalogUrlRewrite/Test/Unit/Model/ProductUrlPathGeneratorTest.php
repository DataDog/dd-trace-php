<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CatalogUrlRewrite\Test\Unit\Model;

use Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Store\Model\ScopeInterface;

/**
 * Class ProductUrlPathGeneratorTest
 */
class ProductUrlPathGeneratorTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator */
    protected $productUrlPathGenerator;

    /** @var \Magento\Store\Model\StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $storeManager;

    /** @var \Magento\Framework\App\Config\ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $scopeConfig;

    /** @var \Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator|\PHPUnit\Framework\MockObject\MockObject */
    protected $categoryUrlPathGenerator;

    /** @var \Magento\Catalog\Model\Product|\PHPUnit\Framework\MockObject\MockObject */
    protected $product;

    /** @var \Magento\Catalog\Api\ProductRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $productRepository;

    /** @var \Magento\Catalog\Model\Category|\PHPUnit\Framework\MockObject\MockObject */
    protected $category;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->category = $this->createMock(\Magento\Catalog\Model\Category::class);
        $productMethods = [
            '__wakeup',
            'getData',
            'getUrlKey',
            'getName',
            'formatUrlKey',
            'getId',
            'load',
            'setStoreId',
        ];

        $this->product = $this->createPartialMock(\Magento\Catalog\Model\Product::class, $productMethods);
        $this->storeManager = $this->createMock(\Magento\Store\Model\StoreManagerInterface::class);
        $this->scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $this->categoryUrlPathGenerator = $this->createMock(
            \Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator::class
        );
        $this->productRepository = $this->createMock(\Magento\Catalog\Api\ProductRepositoryInterface::class);
        $this->productRepository->expects($this->any())->method('getById')->willReturn($this->product);

        $this->productUrlPathGenerator = (new ObjectManager($this))->getObject(
            \Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator::class,
            [
                'storeManager' => $this->storeManager,
                'scopeConfig' => $this->scopeConfig,
                'categoryUrlPathGenerator' => $this->categoryUrlPathGenerator,
                'productRepository' => $this->productRepository,
            ]
        );
    }

    /**
     * @return array
     */
    public function getUrlPathDataProvider(): array
    {
        return [
            'path based on url key uppercase' => ['Url-Key', null, 0, 'url-key'],
            'path based on url key' => ['url-key', null, 0, 'url-key'],
            'path based on product name 1' => ['', 'product-name', 1, 'product-name'],
            'path based on product name 2' => [null, 'product-name', 1, 'product-name'],
            'path based on product name 3' => [false, 'product-name', 1, 'product-name']
        ];
    }

    /**
     * @dataProvider getUrlPathDataProvider
     * @param string|null|bool $urlKey
     * @param string|null|bool $productName
     * @param int $formatterCalled
     * @param string $result
     * @return void
     */
    public function testGetUrlPath($urlKey, $productName, $formatterCalled, $result): void
    {
        $this->product->expects($this->once())->method('getData')->with('url_path')
            ->willReturn(null);
        $this->product->expects($this->any())->method('getUrlKey')->willReturn($urlKey);
        $this->product->expects($this->any())->method('getName')->willReturn($productName);
        $this->product->expects($this->exactly($formatterCalled))
            ->method('formatUrlKey')->willReturnArgument(0);

        $this->assertEquals($result, $this->productUrlPathGenerator->getUrlPath($this->product, null));
    }

    /**
     * @param string|bool $productUrlKey
     * @param string|bool $expectedUrlKey
     * @return void
     * @dataProvider getUrlKeyDataProvider
     */
    public function testGetUrlKey($productUrlKey, $expectedUrlKey): void
    {
        $this->product->expects($this->any())->method('getUrlKey')->willReturn($productUrlKey);
        $this->product->expects($this->any())->method('formatUrlKey')->willReturn($productUrlKey);
        $this->assertSame($expectedUrlKey, $this->productUrlPathGenerator->getUrlKey($this->product));
    }

    /**
     * @return array
     */
    public function getUrlKeyDataProvider(): array
    {
        return [
            'URL Key use default' => [false, null],
            'URL Key empty' => ['product-url', 'product-url'],
        ];
    }

    /**
     * @param string|null|bool $storedUrlKey
     * @param string|null|bool $productName
     * @param string $expectedUrlKey
     * @return void
     * @dataProvider getUrlPathDefaultUrlKeyDataProvider
     */
    public function testGetUrlPathDefaultUrlKey($storedUrlKey, $productName, $expectedUrlKey): void
    {
        $this->product->expects($this->once())->method('getData')->with('url_path')
            ->willReturn(null);
        $this->product->expects($this->any())->method('getUrlKey')->willReturnOnConsecutiveCalls(false, $storedUrlKey);
        $this->product->expects($this->any())->method('getName')->willReturn($productName);
        $this->product->expects($this->any())->method('formatUrlKey')->willReturnArgument(0);
        $this->assertEquals($expectedUrlKey, $this->productUrlPathGenerator->getUrlPath($this->product, null));
    }

    /**
     * @return array
     */
    public function getUrlPathDefaultUrlKeyDataProvider(): array
    {
        return [
            ['default-store-view-url-key', null, 'default-store-view-url-key'],
            [false, 'default-store-view-product-name', 'default-store-view-product-name']
        ];
    }

    /**
     * @return void
     */
    public function testGetUrlPathWithCategory(): void
    {
        $this->product->expects($this->once())->method('getData')->with('url_path')
            ->willReturn('product-path');
        $this->categoryUrlPathGenerator->expects($this->once())->method('getUrlPath')
            ->willReturn('category-url-path');

        $this->assertEquals(
            'category-url-path/product-path',
            $this->productUrlPathGenerator->getUrlPath($this->product, $this->category)
        );
    }

    /**
     * @return void
     */
    public function testGetUrlPathWithSuffix(): void
    {
        $storeId = 1;
        $this->product->expects($this->once())->method('getData')->with('url_path')
            ->willReturn('product-path');
        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $store->expects($this->once())->method('getId')->willReturn($storeId);
        $this->storeManager->expects($this->once())->method('getStore')->willReturn($store);
        $this->scopeConfig->expects($this->once())->method('getValue')
            ->with(ProductUrlPathGenerator::XML_PATH_PRODUCT_URL_SUFFIX, ScopeInterface::SCOPE_STORE, $storeId)
            ->willReturn('.html');

        $this->assertEquals(
            'product-path.html',
            $this->productUrlPathGenerator->getUrlPathWithSuffix($this->product, null)
        );
    }

    /**
     * @return void
     */
    public function testGetUrlPathWithSuffixAndCategoryAndStore(): void
    {
        $storeId = 1;
        $this->product->expects($this->once())->method('getData')->with('url_path')
            ->willReturn('product-path');
        $this->categoryUrlPathGenerator->expects($this->once())->method('getUrlPath')
            ->willReturn('category-url-path');
        $this->storeManager->expects($this->never())->method('getStore');
        $this->scopeConfig->expects($this->once())->method('getValue')
            ->with(ProductUrlPathGenerator::XML_PATH_PRODUCT_URL_SUFFIX, ScopeInterface::SCOPE_STORE, $storeId)
            ->willReturn('.html');

        $this->assertEquals(
            'category-url-path/product-path.html',
            $this->productUrlPathGenerator->getUrlPathWithSuffix($this->product, $storeId, $this->category)
        );
    }

    /**
     * @return void
     */
    public function testGetCanonicalUrlPath(): void
    {
        $this->product->expects($this->once())->method('getId')->willReturn(1);

        $this->assertEquals(
            'catalog/product/view/id/1',
            $this->productUrlPathGenerator->getCanonicalUrlPath($this->product)
        );
    }

    /**
     * @return void
     */
    public function testGetCanonicalUrlPathWithCategory(): void
    {
        $this->product->expects($this->once())->method('getId')->willReturn(1);
        $this->category->expects($this->once())->method('getId')->willReturn(1);

        $this->assertEquals(
            'catalog/product/view/id/1/category/1',
            $this->productUrlPathGenerator->getCanonicalUrlPath($this->product, $this->category)
        );
    }
}
