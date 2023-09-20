<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Tax\Test\Unit\Model\Calculation;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * Test for TaxRuleRegistry
 *
 */
class TaxRuleRegistryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Tax\Model\Calculation\TaxRuleRegistry
     */
    private $taxRuleRegistry;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Tax\Model\Calculation\RuleFactory
     */
    private $taxRuleModelFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Tax\Model\Calculation\Rule
     */
    private $taxRuleModelMock;

    const TAX_RULE_ID = 1;

    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);
        $this->taxRuleModelFactoryMock = $this->getMockBuilder(\Magento\Tax\Model\Calculation\RuleFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->taxRuleRegistry = $objectManager->getObject(
            \Magento\Tax\Model\Calculation\TaxRuleRegistry::class,
            ['taxRuleModelFactory' => $this->taxRuleModelFactoryMock]
        );
        $this->taxRuleModelMock = $this->getMockBuilder(\Magento\Tax\Model\Calculation\Rule::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function testRemoveTaxRule()
    {
        $this->taxRuleModelMock->expects($this->any())
            ->method('load')
            ->with(self::TAX_RULE_ID)
            ->willReturn($this->taxRuleModelMock);

        $this->taxRuleModelMock->expects($this->any())
            ->method('getId')
            ->will($this->onConsecutiveCalls(self::TAX_RULE_ID, null));

        $this->taxRuleModelFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($this->taxRuleModelMock);
        $this->taxRuleRegistry->registerTaxRule($this->taxRuleModelMock);
        $expected = $this->taxRuleRegistry->retrieveTaxRule(self::TAX_RULE_ID);
        $this->assertEquals($this->taxRuleModelMock, $expected);

        // Remove the tax rule
        $this->taxRuleRegistry->removeTaxRule(self::TAX_RULE_ID);

        // Verify that if the tax rule is retrieved again, an exception is thrown
        try {
            $this->taxRuleRegistry->retrieveTaxRule(self::TAX_RULE_ID);
            $this->fail('NoSuchEntityException was not thrown as expected');
        } catch (NoSuchEntityException $e) {
            $expectedParams = [
                'fieldName' => 'taxRuleId',
                'fieldValue' => self::TAX_RULE_ID,
            ];
            $this->assertEquals($expectedParams, $e->getParameters());
        }
    }
}
