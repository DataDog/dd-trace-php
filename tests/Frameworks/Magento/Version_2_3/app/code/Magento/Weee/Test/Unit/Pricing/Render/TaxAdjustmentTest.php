<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Weee\Test\Unit\Pricing\Render;

class TaxAdjustmentTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Weee\Pricing\Render\TaxAdjustment
     */
    protected $model;

    /**
     * Weee helper mock
     *
     * @var \Magento\Weee\Helper\Data | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $weeeHelperMock;

    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManager;

    /**
     * Init mocks and model
     */
    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->weeeHelperMock = $this->createPartialMock(
            \Magento\Weee\Helper\Data::class,
            ['typeOfDisplay', 'isTaxable']
        );

        $this->model = $this->objectManager->getObject(
            \Magento\Weee\Pricing\Render\TaxAdjustment::class,
            [
                'weeeHelper' => $this->weeeHelperMock,
            ]
        );
    }

    /**
     * Test for method getDefaultExclusions
     *
     * @dataProvider getDefaultExclusionsDataProvider
     */
    public function testGetDefaultExclusions($weeeIsExcluded)
    {
        //setup
        $this->weeeHelperMock->expects($this->atLeastOnce())->method('typeOfDisplay')->willReturn($weeeIsExcluded);

        //test
        $defaultExclusions = $this->model->getDefaultExclusions();
        $this->assertNotEmpty($defaultExclusions, 'Expected to have at least one default exclusion: tax');

        $taxCode = $this->model->getAdjustmentCode(); // since Weee's TaxAdjustment is a subclass of Tax's Adjustment
        $this->assertContains($taxCode, $defaultExclusions);

        $weeeCode = \Magento\Weee\Pricing\Adjustment::ADJUSTMENT_CODE;
        if ($weeeIsExcluded) {
            $this->assertContains($weeeCode, $defaultExclusions);
        } else {
            $this->assertNotContains($weeeCode, $defaultExclusions);
        }
    }

    /**
     * Data provider for testGetDefaultExclusions()
     * @return array
     */
    public function getDefaultExclusionsDataProvider()
    {
        return [
            'weee part of exclusions' => [true],
            'weee not part of exclusions' => [false],
        ];
    }
}
