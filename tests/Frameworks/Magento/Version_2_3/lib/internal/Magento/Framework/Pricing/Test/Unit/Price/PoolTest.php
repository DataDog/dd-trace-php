<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Pricing\Test\Unit\Price;

use \Magento\Framework\Pricing\Price\Pool;

/**
 * Test for Pool
 */
class PoolTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Pricing\Price\Pool
     */
    protected $pool;

    /**
     * @var array
     */
    protected $prices;

    /**
     * @var array
     */
    protected $target;

    /**
     * \Iterator
     */
    protected $targetPool;

    /**
     * Test setUp
     */
    protected function setUp(): void
    {
        $this->prices = [
            'regular_price' => 'RegularPrice',
            'special_price' => 'SpecialPrice',
        ];
        $this->target = [
            'regular_price' => 'TargetRegularPrice',
        ];
        $this->targetPool = new Pool($this->target);
        $this->pool = new Pool($this->prices, $this->targetPool);
    }

    /**
     * test mergedConfiguration
     */
    public function testMergedConfiguration()
    {
        $expected = new Pool([
            'regular_price' => 'RegularPrice',
            'special_price' => 'SpecialPrice',
        ]);
        $this->assertEquals($expected, $this->pool);
    }

    /**
     * Test get method
     */
    public function testGet()
    {
        $this->assertEquals('RegularPrice', $this->pool->get('regular_price'));
        $this->assertEquals('SpecialPrice', $this->pool->get('special_price'));
    }

    /**
     * Test abilities of ArrayAccess interface
     */
    public function testArrayAccess()
    {
        $this->assertEquals('RegularPrice', $this->pool['regular_price']);
        $this->assertEquals('SpecialPrice', $this->pool['special_price']);
        $this->pool['fake_price'] = 'FakePrice';
        $this->assertEquals('FakePrice', $this->pool['fake_price']);
        $this->assertTrue(isset($this->pool['fake_price']));
        unset($this->pool['fake_price']);
        $this->assertFalse(isset($this->pool['fake_price']));
        $this->assertNull($this->pool['fake_price']);
    }

    /**
     * Test abilities of Iterator interface
     */
    public function testIterator()
    {
        foreach ($this->pool as $code => $class) {
            $this->assertEquals($this->pool[$code], $class);
        }
    }
}
