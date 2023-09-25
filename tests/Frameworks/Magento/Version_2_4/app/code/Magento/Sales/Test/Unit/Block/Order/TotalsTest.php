<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Sales\Test\Unit\Block\Order;

use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Block\Order\Totals;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Total;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TotalsTest extends TestCase
{
    /**
     * @var Totals
     */
    protected $block;

    /**
     * @var Context|MockObject
     */
    protected $context;

    protected function setUp(): void
    {
        $this->context = $this->createMock(Context::class);
        $this->block = new Totals($this->context, new Registry());
        $this->block->setOrder($this->createMock(Order::class));
    }

    public function testApplySortOrder()
    {
        $this->block->addTotal(new Total(['code' => 'one']), 'last');
        $this->block->addTotal(new Total(['code' => 'two']), 'last');
        $this->block->addTotal(new Total(['code' => 'three']), 'last');
        $this->block->applySortOrder(
            [
                'one' => 10,
                'two' => 30,
                'three' => 20,
            ]
        );
        $this->assertEqualsSorted(
            [
                'one' => new Total(['code' => 'one']),
                'three' => new Total(['code' => 'three']),
                'two' => new Total(['code' => 'two']),
            ],
            $this->block->getTotals()
        );
    }

    /**
     * @param array $expected
     * @param array $actual
     */
    private function assertEqualsSorted(array $expected, array $actual)
    {
        $this->assertEquals($expected, $actual, 'Array contents should be equal.');
        $this->assertEquals(array_keys($expected), array_keys($actual), 'Array sort order should be equal.');
    }
}
