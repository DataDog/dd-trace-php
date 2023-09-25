<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Checkout\Test\Unit\Block\Checkout;

class TotalsProcessorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Checkout\Block\Checkout\TotalsProcessor
     */
    protected $model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $scopeConfigMock;

    protected function setUp(): void
    {
        $this->scopeConfigMock = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);

        $this->model = new \Magento\Checkout\Block\Checkout\TotalsProcessor($this->scopeConfigMock);
    }

    public function testProcess()
    {
        $jsLayoutData = [
            'sub-total' => [],
            'grand-total' => [],
            'non-existant-total' => null
        ];
        $expectedResultData = [
            'sub-total' => ['sortOrder' => 10],
            'grand-total' => ['sortOrder' => 20],
            'non-existant-total' => null
        ];
        $jsLayout['components']['checkout']['children']['sidebar']['children']['summary']
            ['children']['totals']['children'] = $jsLayoutData;
        $expectedResult['components']['checkout']['children']['sidebar']['children']['summary']
            ['children']['totals']['children'] = $expectedResultData;

        $configData = ['sub_total' => 10, 'grand_total' => 20];

        $this->scopeConfigMock->expects($this->once())->method('getValue')->with('sales/totals_sort')
            ->willReturn($configData);

        $this->assertEquals($expectedResult, $this->model->process($jsLayout));
    }
}
