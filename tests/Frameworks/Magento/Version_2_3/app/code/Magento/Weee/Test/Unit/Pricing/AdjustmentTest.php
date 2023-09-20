<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Weee\Test\Unit\Pricing;

use \Magento\Weee\Pricing\Adjustment;

use Magento\Framework\Pricing\SaleableInterface;
use Magento\Weee\Helper\Data as WeeeHelper;

class AdjustmentTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Adjustment
     */
    protected $adjustment;

    /**
     * @var \Magento\Weee\Helper\Data | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $weeeHelper;

    /**
     * @var \Magento\Framework\Pricing\PriceCurrencyInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $priceCurrencyMock;

    /**
     * @var int
     */
    protected $sortOrder = 5;

    protected function setUp(): void
    {
        $this->weeeHelper = $this->createMock(\Magento\Weee\Helper\Data::class);
        $this->priceCurrencyMock = $this->createMock(\Magento\Framework\Pricing\PriceCurrencyInterface::class);
        $this->priceCurrencyMock->expects($this->any())
            ->method('convertAndRound')
            ->willReturnCallback(
                
                    function ($arg) {
                        return round($arg * 0.5, 2);
                    }
                
            );
        $this->priceCurrencyMock->expects($this->any())
            ->method('convert')
            ->willReturnCallback(
                
                    function ($arg) {
                        return $arg * 0.5;
                    }
                
            );

        $this->adjustment = new Adjustment($this->weeeHelper, $this->priceCurrencyMock, $this->sortOrder);
    }

    public function testGetAdjustmentCode()
    {
        $this->assertEquals(Adjustment::ADJUSTMENT_CODE, $this->adjustment->getAdjustmentCode());
    }

    public function testIsIncludedInBasePrice()
    {
        $this->assertFalse($this->adjustment->isIncludedInBasePrice());
    }

    /**
     * @dataProvider isIncludedInDisplayPriceDataProvider
     */
    public function testIsIncludedInDisplayPrice($expectedResult)
    {
        $displayTypes = [
            \Magento\Weee\Model\Tax::DISPLAY_INCL,
            \Magento\Weee\Model\Tax::DISPLAY_INCL_DESCR,
            \Magento\Weee\Model\Tax::DISPLAY_EXCL_DESCR_INCL,
        ];
        $this->weeeHelper->expects($this->any())
            ->method('typeOfDisplay')
            ->with($displayTypes)
            ->willReturn($expectedResult);

        $this->assertEquals($expectedResult, $this->adjustment->isIncludedInDisplayPrice());
    }

    /**
     * @return array
     */
    public function isIncludedInDisplayPriceDataProvider()
    {
        return [[false], [true]];
    }

    /**
     * @param float $amount
     * @param float $amountOld
     * @param float $expectedResult
     * @dataProvider applyAdjustmentDataProvider
     */
    public function testApplyAdjustment($amount, $amountOld, $expectedResult)
    {
        $object = $this->getMockForAbstractClass(\Magento\Framework\Pricing\SaleableInterface::class);

        $this->weeeHelper->expects($this->any())
            ->method('getAmountExclTax')
            ->willReturn($amountOld);

        $this->assertEquals($expectedResult, $this->adjustment->applyAdjustment($amount, $object));
    }

    /**
     * @return array
     */
    public function applyAdjustmentDataProvider()
    {
        return [
            [1.1, 2.4, 2.3],
            [0.0, 2.2, 1.1],
            [1.1, 0.0, 1.1],
        ];
    }

    /**
     * @dataProvider isExcludedWithDataProvider
     * @param string $adjustmentCode
     * @param bool $expectedResult
     */
    public function testIsExcludedWith($adjustmentCode, $expectedResult)
    {
        $this->assertEquals($expectedResult, $this->adjustment->isExcludedWith($adjustmentCode));
    }

    /**
     * @return array
     */
    public function isExcludedWithDataProvider()
    {
        return [
            ['weee', true],
            ['tax', true],
            ['not_tax_and_not_weee', false]
        ];
    }

    /**
     * @dataProvider getSortOrderProvider
     * @param bool $isTaxable
     * @param int $expectedResult
     */
    public function testGetSortOrder($isTaxable, $expectedResult)
    {
        $this->weeeHelper->expects($this->any())
            ->method('isTaxable')
            ->willReturn($isTaxable);

        $this->assertEquals($expectedResult, $this->adjustment->getSortOrder());
    }

    /**
     * @return array
     */
    public function getSortOrderProvider()
    {
        return [
            [true, $this->sortOrder],
            [false, $this->sortOrder]
        ];
    }
}
