<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Tax\Pricing;

class AdjustmentTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @param $isShippingPriceExcludeTax
     * @param $expectedResult
     */
    protected function isIncludedInBasePricePrice($isShippingPriceExcludeTax, $expectedResult)
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        /** @var \Magento\Tax\Model\Config $config */
        $config = $objectManager->get(\Magento\Tax\Model\Config::class);
        /** @var \Magento\Tax\Pricing\Adjustment $model */
        $model = $objectManager->create(\Magento\Tax\Pricing\Adjustment::class);
        $config->setNeedUseShippingExcludeTax($isShippingPriceExcludeTax);
        // Run tested method
        $result = $model->isIncludedInBasePrice();
        // Check expectations
        $this->assertIsBool($result);
        $this->assertEquals($expectedResult, $result);
    }

    /**
     * @param bool $isShippingPriceExcludeTax
     * @param bool $expectedResult
     * @magentoConfigFixture current_store tax/calculation/price_includes_tax 1
     * @dataProvider isIncludedInBasePricePriceIncludeTaxEnabledDataProvider
     */
    public function testIsIncludedInBasePricePriceIncludeTacEnabled($isShippingPriceExcludeTax, $expectedResult)
    {
        $this->isIncludedInBasePricePrice($isShippingPriceExcludeTax, $expectedResult);
    }

    /**
     * @param bool $isShippingPriceExcludeTax
     * @param bool $expectedResult
     * @magentoConfigFixture current_store tax/calculation/price_includes_tax 0
     * @dataProvider isIncludedInBasePricePriceIncludeTaxDisabledDataProvider
     */
    public function testIsIncludedInBasePricePriceIncludeTacDisabled($isShippingPriceExcludeTax, $expectedResult)
    {
        $this->isIncludedInBasePricePrice($isShippingPriceExcludeTax, $expectedResult);
    }

    /**
     * @return array
     */
    public function isIncludedInBasePricePriceIncludeTaxEnabledDataProvider()
    {
        return [
            [0, true],
            [1, true],
        ];
    }

    /**
     * @return array
     */
    public function isIncludedInBasePricePriceIncludeTaxDisabledDataProvider()
    {
        return [
            [0, false],
            [1, true],
        ];
    }

    /**
     * test template for isIncludedInDisplayPrice
     *
     * @param $expectedResult
     */
    protected function isIncludedInDisplayPrice($expectedResult)
    {
        // Instantiate objects
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        /** @var \Magento\Tax\Pricing\Adjustment $model */
        $model = $objectManager->create(\Magento\Tax\Pricing\Adjustment::class);
        // Run tested method
        $result = $model->isIncludedInDisplayPrice();
        // Check expectations
        $this->assertIsBool($result);
        $this->assertEquals($expectedResult, $result);
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store tax/display/type 1
     */
    public function testIsIncludedInDisplayPriceExcludingTax()
    {
        $this->isIncludedInDisplayPrice(false);
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store tax/display/type 2
     */
    public function testIsIncludedInDisplayPriceIncludingTax()
    {
        $this->isIncludedInDisplayPrice(true);
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store tax/display/type 3
     */
    public function testIsIncludedInDisplayPriceBoth()
    {
        $this->isIncludedInDisplayPrice(true);
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store tax/display/type 100500
     */
    public function testIsIncludedInDisplayPriceWrongValue()
    {
        $this->isIncludedInDisplayPrice(false);
    }
}
