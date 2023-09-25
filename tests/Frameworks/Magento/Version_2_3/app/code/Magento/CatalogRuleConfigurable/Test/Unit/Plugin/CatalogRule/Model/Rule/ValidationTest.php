<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\CatalogRuleConfigurable\Test\Unit\Plugin\CatalogRule\Model\Rule;

use Magento\CatalogRuleConfigurable\Plugin\CatalogRule\Model\Rule\Validation;

/**
 * Unit test for Magento\CatalogRuleConfigurable\Plugin\CatalogRule\Model\Rule\Validation
 */
class ValidationTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\CatalogRuleConfigurable\Plugin\CatalogRule\Model\Rule\Validation
     */
    private $validation;

    /**
     * @var \Magento\ConfigurableProduct\Model\Product\Type\Configurable|\PHPUnit\Framework\MockObject\MockObject
     */
    private $configurableMock;

    /** @var \Magento\CatalogRule\Model\Rule|\PHPUnit\Framework\MockObject\MockObject */
    private $ruleMock;

    /** @var \Magento\Rule\Model\Condition\Combine|\PHPUnit\Framework\MockObject\MockObject */
    private $ruleConditionsMock;

    /** @var \Magento\Framework\DataObject|\PHPUnit\Framework\MockObject\MockObject */
    private $productMock;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        $this->configurableMock = $this->createPartialMock(
            \Magento\ConfigurableProduct\Model\Product\Type\Configurable::class,
            ['getParentIdsByChild']
        );

        $this->ruleMock = $this->createMock(\Magento\CatalogRule\Model\Rule::class);
        $this->ruleConditionsMock = $this->createMock(\Magento\Rule\Model\Condition\Combine::class);
        $this->productMock = $this->createPartialMock(\Magento\Framework\DataObject::class, ['getId']);

        $this->validation = new Validation(
            $this->configurableMock
        );
    }

    /**
     * @param $parentsIds
     * @param $validationResult
     * @param $runValidateAmount
     * @param $result
     * @dataProvider dataProviderForValidateWithValidConfigurableProduct
     * @return void
     */
    public function testAfterValidateWithValidConfigurableProduct(
        $parentsIds,
        $validationResult,
        $runValidateAmount,
        $result
    ) {
        $this->productMock->expects($this->once())->method('getId')->willReturn('product_id');
        $this->configurableMock->expects($this->once())->method('getParentIdsByChild')->with('product_id')
            ->willReturn($parentsIds);
        $this->ruleMock->expects($this->exactly($runValidateAmount))->method('getConditions')
            ->willReturn($this->ruleConditionsMock);
        $this->ruleConditionsMock->expects($this->exactly($runValidateAmount))->method('validateByEntityId')
            ->willReturnMap($validationResult);

        $this->assertEquals(
            $result,
            $this->validation->afterValidate($this->ruleMock, false, $this->productMock)
        );
    }

    /**
     * @return array
     */
    public function dataProviderForValidateWithValidConfigurableProduct()
    {
        return [
            [
                [1, 2, 3],
                [
                    [1, false],
                    [2, true],
                    [3, true],
                ],
                2,
                true,
            ],
            [
                [1, 2, 3],
                [
                    [1, true],
                    [2, false],
                    [3, true],
                ],
                1,
                true,
            ],
            [
                [1, 2, 3],
                [
                    [1, false],
                    [2, false],
                    [3, false],
                ],
                3,
                false,
            ],
        ];
    }
}
