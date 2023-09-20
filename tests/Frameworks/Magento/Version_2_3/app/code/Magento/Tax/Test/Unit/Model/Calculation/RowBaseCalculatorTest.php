<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Tax\Test\Unit\Model\Calculation;

use \Magento\Tax\Model\Calculation\RowBaseCalculator;

/**
 * Class RowBaseCalculatorTest
 *
 */
class RowBaseCalculatorTest extends RowBaseAndTotalBaseCalculatorTestCase
{
    /** @var RowBaseCalculator | \PHPUnit\Framework\MockObject\MockObject */
    protected $rowBaseCalculator;

    public function testCalculateWithTaxInPrice()
    {
        $this->initMocks(true);
        $this->initRowBaseCalculator();
        $this->rowBaseCalculator->expects($this->atLeastOnce())
            ->method('deltaRound')->willReturn(0);

        $this->assertSame(
            $this->taxDetailsItem,
            $this->calculate($this->rowBaseCalculator, true)
        );
        $this->assertEquals(self::UNIT_PRICE_INCL_TAX_ROUNDED, $this->taxDetailsItem->getPriceInclTax());

        $this->assertSame(
            $this->taxDetailsItem,
            $this->calculate($this->rowBaseCalculator, false)
        );
        $this->assertEquals(self::UNIT_PRICE_INCL_TAX, $this->taxDetailsItem->getPriceInclTax());
    }

    public function testCalculateWithTaxNotInPrice()
    {
        $this->initMocks(false);
        $this->initRowBaseCalculator();
        $this->rowBaseCalculator->expects($this->atLeastOnce())
            ->method('deltaRound');

        $this->assertSame(
            $this->taxDetailsItem,
            $this->calculate($this->rowBaseCalculator)
        );
    }

    private function initRowBaseCalculator()
    {
        $taxClassService = $this->createMock(\Magento\Tax\Api\TaxClassManagementInterface::class);
        $this->rowBaseCalculator = $this->getMockBuilder(\Magento\Tax\Model\Calculation\RowBaseCalculator::class)
            ->setMethods(['deltaRound'])
            ->setConstructorArgs(
                [
                    'taxClassService' => $taxClassService,
                    'taxDetailsItemDataObjectFactory' => $this->taxItemDetailsDataObjectFactory,
                    'appliedTaxDataObjectFactory' => $this->appliedTaxDataObjectFactory,
                    'appliedTaxRateDataObjectFactory' => $this->appliedTaxRateDataObjectFactory,
                    'calculationTool' => $this->mockCalculationTool,
                    'config' => $this->mockConfig,
                    'storeId' => self::STORE_ID,
                    'addressRateRequest' => $this->addressRateRequest
                ]
            )
            ->getMock();
    }
}
