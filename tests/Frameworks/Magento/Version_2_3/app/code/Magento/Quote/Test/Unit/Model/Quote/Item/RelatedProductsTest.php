<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Quote\Test\Unit\Model\Quote\Item;

class RelatedProductsTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Quote\Model\Quote\Item\RelatedProducts
     */
    protected $model;

    /**
     * @var array
     */
    protected $relatedProductTypes;

    protected function setUp(): void
    {
        $this->relatedProductTypes = ['type1', 'type2', 'type3'];
        $this->model = new \Magento\Quote\Model\Quote\Item\RelatedProducts($this->relatedProductTypes);
    }

    /**
     * @param string $optionValue
     * @param int|bool $productId
     * @param array $expectedResult
     *
     * @covers \Magento\Quote\Model\Quote\Item\RelatedProducts::getRelatedProductIds
     * @dataProvider getRelatedProductIdsDataProvider
     */
    public function testGetRelatedProductIds($optionValue, $productId, $expectedResult)
    {
        $quoteItemMock = $this->createMock(\Magento\Quote\Model\Quote\Item::class);
        $itemOptionMock = $this->createPartialMock(
            \Magento\Quote\Model\Quote\Item\Option::class,
            ['getValue', 'getProductId', '__wakeup']
        );

        $quoteItemMock->expects(
            $this->once()
        )->method(
            'getOptionByCode'
        )->with(
            'product_type'
        )->willReturn(
            $itemOptionMock
        );

        $itemOptionMock->expects($this->once())->method('getValue')->willReturn($optionValue);

        $itemOptionMock->expects($this->any())->method('getProductId')->willReturn($productId);

        $this->assertEquals($expectedResult, $this->model->getRelatedProductIds([$quoteItemMock]));
    }

    /*
     * Data provider for testGetRelatedProductIds
     *
     * @return array
     */
    /**
     * @return array
     */
    public function getRelatedProductIdsDataProvider()
    {
        return [
            ['optionValue' => 'type1', 'productId' => 123, 'expectedResult' => [123]],
            ['optionValue' => 'other_type', 'productId' => 123, 'expectedResult' => []],
            ['optionValue' => 'type1', 'productId' => null, 'expectedResult' => []],
            ['optionValue' => 'other_type', 'productId' => false, 'expectedResult' => []]
        ];
    }

    /**
     * @covers \Magento\Quote\Model\Quote\Item\RelatedProducts::getRelatedProductIds
     */
    public function testGetRelatedProductIdsNoOptions()
    {
        $quoteItemMock = $this->createMock(\Magento\Quote\Model\Quote\Item::class);

        $quoteItemMock->expects(
            $this->once()
        )->method(
            'getOptionByCode'
        )->with(
            'product_type'
        )->willReturn(
            new \stdClass()
        );

        $this->assertEquals([], $this->model->getRelatedProductIds([$quoteItemMock]));
    }
}
