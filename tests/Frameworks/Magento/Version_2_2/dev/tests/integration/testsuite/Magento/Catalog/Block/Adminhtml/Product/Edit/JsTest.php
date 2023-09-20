<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Block\Adminhtml\Product\Edit;

/**
 * @magentoAppArea adminhtml
 */
class JsTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @magentoDataFixture Magento/Tax/_files/tax_classes.php
     */
    public function testGetAllRatesByProductClassJson()
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        /** @var \Magento\Tax\Model\Calculation\Rule $fixtureTaxRule */
        $fixtureTaxRule = $objectManager->create(\Magento\Tax\Model\Calculation\Rule::class);
        $fixtureTaxRule->load('Test Rule', 'code');
        $defaultCustomerTaxClass = 3;
        $fixtureTaxRule
            ->setCustomerTaxClassIds(array_merge($fixtureTaxRule->getCustomerTaxClasses(), [$defaultCustomerTaxClass]))
            ->setProductTaxClassIds($fixtureTaxRule->getProductTaxClasses())
            ->setTaxRateIds($fixtureTaxRule->getRates())
            ->saveCalculationData();
        /** @var \Magento\Catalog\Block\Adminhtml\Product\Edit\Js $block */
        $block = $objectManager->create(\Magento\Catalog\Block\Adminhtml\Product\Edit\Js::class);
        $jsonResult = $block->getAllRatesByProductClassJson();
        $this->assertJson($jsonResult, 'Resulting JSON is invalid.');
        $decodedResult = json_decode($jsonResult, true);
        $this->assertNotNull($decodedResult, 'Cannot decode resulting JSON.');
        $noneTaxClass = 0;
        $defaultProductTaxClass = 2;
        $expectedProductTaxClasses = array_unique(
            array_merge($fixtureTaxRule->getProductTaxClasses(), [$defaultProductTaxClass, $noneTaxClass])
        );
        foreach ($expectedProductTaxClasses as $taxClassId) {
            $this->assertArrayHasKey(
                "value_{$taxClassId}",
                $decodedResult,
                "Rates for tax class with ID '{$taxClassId}' is missing."
            );
        }
        $this->assertContains('7.5', $jsonResult, 'Rates for tax classes looks to be invalid.');
    }
}
