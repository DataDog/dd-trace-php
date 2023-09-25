<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Model\Product;

use \Magento\Catalog\Model\Product\Option;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class OptionTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Model\Product\Option
     */
    protected $model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $productMock;

    protected function setUp(): void
    {
        $this->productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $objectManager = new ObjectManager($this);
        $this->model = $objectManager->getObject(\Magento\Catalog\Model\Product\Option::class);
        $this->model->setProduct($this->productMock);
    }

    public function testGetProductSku()
    {
        $productSku = 'product-sku';
        $this->productMock->expects($this->once())->method('getSku')->willReturn($productSku);
        $this->assertEquals($productSku, $this->model->getProductSku());
    }

    public function testHasValues()
    {
        $this->model->setType('drop_down');
        $this->assertTrue($this->model->hasValues());

        $this->model->setType('field');
        $this->assertFalse($this->model->hasValues());
    }

    public function testGetRegularPrice()
    {
        $priceInfoMock = $this->getMockForAbstractClass(
            \Magento\Framework\Pricing\PriceInfoInterface::class,
            [],
            '',
            false,
            false,
            true,
            ['getAmount', 'getPrice']
        );
        $priceInfoMock->expects($this->once())->method('getPrice')->willReturnSelf();
        $amountMock = $this->getMockForAbstractClass(\Magento\Framework\Pricing\Amount\AmountInterface::class);
        $priceInfoMock->expects($this->once())->method('getAmount')->willReturn($amountMock);

        $this->productMock->expects($this->once())->method('getPriceInfo')->willReturn($priceInfoMock);

        $amountMock->expects($this->once())->method('getValue')->willReturn(50);
        $this->model->setPrice(50);
        $this->model->setPriceType(\Magento\Catalog\Model\Product\Option\Value::TYPE_PERCENT);
        $this->assertEquals(25, $this->model->getRegularPrice());
        $this->model->setPriceType(null);
        $this->assertEquals(50, $this->model->getRegularPrice());
    }

    /**
     * Tests removing ineligible characters from file_extension.
     *
     * @param string $rawExtensions
     * @param string $expectedExtensions
     * @dataProvider cleanFileExtensionsDataProvider
     */
    public function testCleanFileExtensions(string $rawExtensions, string $expectedExtensions)
    {
        $this->model->setType(Option::OPTION_GROUP_FILE);
        $this->model->setFileExtension($rawExtensions);
        $this->model->beforeSave();
        $actualExtensions = $this->model->getFileExtension();
        $this->assertEquals($expectedExtensions, $actualExtensions);
    }

    /**
     * Data provider for testCleanFileExtensions.
     *
     * @return array
     */
    public function cleanFileExtensionsDataProvider()
    {
        return [
            ['JPG, PNG, GIF', 'jpg, png, gif'],
            ['jpg, jpg, jpg', 'jpg'],
            ['jpg, png, gif', 'jpg, png, gif'],
            ['jpg png gif', 'jpg, png, gif'],
            ['!jpg@png#gif%', 'jpg, png, gif'],
            ['jpg, png, 123', 'jpg, png, 123'],
            ['', ''],
        ];
    }
}
