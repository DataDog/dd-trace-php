<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Model\Product\Attribute\Frontend;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Frontend\Image;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ImageTest extends TestCase
{
    /**
     * @var Image
     */
    private $model;

    /**
     * @dataProvider getUrlDataProvider
     * @param string $expectedImage
     * @param string $productImage
     */
    public function testGetUrl(string $expectedImage, string $productImage)
    {
        $this->assertEquals($expectedImage, $this->model->getUrl($this->getMockedProduct($productImage)));
    }

    /**
     * Data provider for testGetUrl
     *
     * @return array
     */
    public function getUrlDataProvider(): array
    {
        return [
            ['catalog/product/img.jpg', 'img.jpg'],
            ['catalog/product/img.jpg', '/img.jpg'],
        ];
    }

    protected function setUp(): void
    {
        $helper = new ObjectManager($this);
        $this->model = $helper->getObject(
            Image::class,
            ['storeManager' => $this->getMockedStoreManager()]
        );
        $this->model->setAttribute($this->getMockedAttribute());
    }

    /**
     * @param string $productImage
     * @return Product
     */
    private function getMockedProduct(string $productImage): Product
    {
        $mockBuilder = $this->getMockBuilder(Product::class);
        $mock = $mockBuilder->setMethods(['getData', 'getStore'])
            ->disableOriginalConstructor()
            ->getMock();

        $mock->expects($this->any())
            ->method('getData')
            ->willReturn($productImage);

        $mock->expects($this->any())
            ->method('getStore');

        return $mock;
    }

    /**
     * @return StoreManagerInterface
     */
    private function getMockedStoreManager(): StoreManagerInterface
    {
        $mockedStore = $this->getMockedStore();

        $mockBuilder = $this->getMockBuilder(StoreManagerInterface::class);
        $mock = $mockBuilder->setMethods(['getStore'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $mock->expects($this->any())
            ->method('getStore')
            ->willReturn($mockedStore);

        return $mock;
    }

    /**
     * @return Store
     */
    private function getMockedStore(): Store
    {
        $mockBuilder = $this->getMockBuilder(Store::class);
        $mock = $mockBuilder->setMethods(['getBaseUrl'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $mock->expects($this->any())
            ->method('getBaseUrl')
            ->willReturn('');

        return $mock;
    }

    /**
     * @return AbstractAttribute
     */
    private function getMockedAttribute(): AbstractAttribute
    {
        $mockBuilder = $this->getMockBuilder(AbstractAttribute::class);
        $mockBuilder->setMethods(['getAttributeCode']);
        $mockBuilder->disableOriginalConstructor();
        $mock = $mockBuilder->getMockForAbstractClass();

        $mock->expects($this->any())
            ->method('getAttributeCode');

        return $mock;
    }
}
