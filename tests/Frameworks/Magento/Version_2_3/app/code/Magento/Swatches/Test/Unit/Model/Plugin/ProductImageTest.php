<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Swatches\Test\Unit\Model\Plugin;

/**
 * Class ProductImage replace original configurable product with first child
 */
class ProductImageTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Swatches\Helper\Data|\PHPUnit\Framework\MockObject\MockObject */
    protected $swatchesHelperMock;

    /** @var \Magento\Catalog\Model\ResourceModel\Eav\AttributeFactory|\PHPUnit\Framework\MockObject\MockObject */
    protected $attributeFactoryMock;

    /** @var \Magento\Eav\Model\Config|\PHPUnit\Framework\MockObject\MockObject */
    protected $eavConfigMock;

    /** @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute|\PHPUnit\Framework\MockObject\MockObject */
    protected $attributeMock;

    /** @var \Magento\Framework\App\Request\Http|\PHPUnit\Framework\MockObject\MockObject */
    protected $requestMock;

    /** @var \Magento\Catalog\Model\Product|\PHPUnit\Framework\MockObject\MockObject */
    protected $productMock;

    /** @var \Magento\Swatches\Model\Plugin\ProductImage|\Magento\Framework\TestFramework\Unit\Helper\ObjectManager */
    protected $pluginModel;

    protected function setUp(): void
    {
        $this->swatchesHelperMock = $this->createPartialMock(
            \Magento\Swatches\Helper\Data::class,
            ['loadVariationByFallback', 'isSwatchAttribute', 'isProductHasSwatch']
        );

        $this->attributeFactoryMock = $this->createPartialMock(
            \Magento\Catalog\Model\ResourceModel\Eav\AttributeFactory::class,
            ['create']
        );

        $this->eavConfigMock = $this->createMock(\Magento\Eav\Model\Config::class);

        $this->attributeMock = $this->createPartialMock(
            \Magento\Catalog\Model\ResourceModel\Eav\Attribute::class,
            ['loadByCode', 'getId', 'getUsedInProductListing', 'getIsFilterable', 'getData']
        );

        $this->requestMock = $this->createPartialMock(\Magento\Framework\App\Request\Http::class, ['getParams']);
        $this->productMock = $this->createMock(\Magento\Catalog\Model\Product::class);

        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->pluginModel = $objectManager->getObject(
            \Magento\Swatches\Model\Plugin\ProductImage::class,
            [
                'swatchesHelperData' => $this->swatchesHelperMock,
                'eavConfig' => $this->eavConfigMock,
                'request' => $this->requestMock,
            ]
        );
    }

    /**
     * @dataProvider dataForTest
     */
    public function testBeforeGetImage($expected)
    {
        $this->productMock->expects($this->once())->method('getTypeId')->willReturn('configurable');

        $this->requestMock
            ->expects($this->once())
            ->method('getParams')
            ->willReturn($expected['getParams']);

        $this->eavConfigMock
            ->method('getEntityAttributes')
            ->with('catalog_product')
            ->willReturn(['color' => $this->attributeMock]);

        $this->canReplaceImageWithSwatch($expected);
        $this->swatchesHelperMock
            ->expects($this->exactly($expected['loadVariationByFallback_count']))
            ->method('loadVariationByFallback')
            ->willReturn($expected['product']);
        $this->swatchesHelperMock
            ->method('isProductHasSwatch')
            ->with($this->productMock)
            ->willReturn(false);

        $productImageMock = $this->createMock(\Magento\Catalog\Block\Product\AbstractProduct::class);

        $result = $this->pluginModel->beforeGetImage($productImageMock, $this->productMock, $expected['page_handle']);
        $this->assertEquals([$this->productMock, $expected['page_handle'], []], $result);
    }

    /**
     * @param $expected
     */
    protected function getFilterArray($expected)
    {
        $this->eavConfigMock
            ->method('getEntityAttributeCodes')
            ->with('catalog_product')
            ->willReturn($expected['attribute_codes_array']);

        $this->eavConfigMock
            ->method('getAttribute')
            ->with('catalog_product', $expected['attribute_code'])
            ->willReturn($this->attributeMock);

        $this->attributeMock
            ->expects($this->exactly($expected['getId_count']))
            ->method('getId')
            ->willReturn($expected['getId']);
    }

    /**
     * @param $expected
     */
    protected function canReplaceImageWithSwatch($expected)
    {
        $this->swatchesHelperMock
            ->expects($this->once())
            ->method('isSwatchAttribute')
            ->with($this->attributeMock)
            ->willReturn($expected['isSwatchAttribute']);

        $this->attributeMock
            ->expects($this->exactly($expected['getUsedInProductListing_count']))
            ->method('getUsedInProductListing')
            ->willReturn($expected['getUsedInProductListing']);

        $this->attributeMock
            ->expects($this->exactly($expected['getIsFilterable_count']))
            ->method('getIsFilterable')
            ->willReturn($expected['getIsFilterable']);

        if ($expected['update_product_preview_image__count'] == 1) {
            $this->attributeMock
                ->method('getData')
                ->with('update_product_preview_image')
                ->willReturn($expected['update_product_preview_image']);
        }
    }

    /**
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function dataForTest()
    {
        $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productMock->expects($this->any())->method('getImage')->willReturn(false);

        return [
            [
                [
                    'page_handle' => 'category_page_grid',
                    'getParams' => ['color' => 31],
                    'attribute_code' => 'color',
                    'getId_count' => 1,
                    'getId' => 332,
                    'isSwatchAttribute' => false,
                    'getUsedInProductListing' => true,
                    'getUsedInProductListing_count' => 1,
                    'getIsFilterable' => true,
                    'getIsFilterable_count' => 1,
                    'update_product_preview_image' =>true,
                    'update_product_preview_image__count' => 1,
                    'loadVariationByFallback_count' => 0,
                    'product' => $productMock,
                ],
            ],
            [
                [
                    'page_handle' => 'category_page_grid',
                    'getParams' => ['color' => 31],
                    'attribute_code' => 'color',
                    'getId_count' => 1,
                    'getId' => 332,
                    'isSwatchAttribute' => true,
                    'getUsedInProductListing' => true,
                    'getUsedInProductListing_count' => 1,
                    'getIsFilterable' => true,
                    'getIsFilterable_count' => 1,
                    'update_product_preview_image' =>true,
                    'update_product_preview_image__count' => 1,
                    'loadVariationByFallback_count' => 1,
                    'product' => $productMock,
                ],
            ],
            [
                [
                    'page_handle' => 'category_page_grid',
                    'getParams' => ['color' => 31],
                    'attribute_code' => 'color',
                    'getId_count' => 1,
                    'getId' => 332,
                    'isSwatchAttribute' => true,
                    'getUsedInProductListing' => true,
                    'getUsedInProductListing_count' => 1,
                    'getIsFilterable' => true,
                    'getIsFilterable_count' => 1,
                    'update_product_preview_image' =>false,
                    'update_product_preview_image__count' => 1,
                    'loadVariationByFallback_count' => 0,
                    'product' => $productMock,
                ],
            ],
        ];
    }
}
