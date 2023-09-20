<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Translation\Test\Unit\Model\Js\Config\Source;

use Magento\Translation\Model\Js\Config;
use Magento\Translation\Model\Js\Config\Source\Strategy;

/**
 * Class StrategyTest
 */
class StrategyTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Strategy
     */
    protected $model;

    /**
     * Set up
     * @return void
     */
    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->model = $objectManager->getObject(\Magento\Translation\Model\Js\Config\Source\Strategy::class);
    }

    /**
     * Test for toOptionArray method
     * @return void
     */
    public function testToOptionArray()
    {
        $expected = [
            ['label' => 'Dictionary (Translation on Storefront side)', 'value' => Config::DICTIONARY_STRATEGY],
            ['label' => 'Embedded (Translation on Admin side)', 'value' => Config::EMBEDDED_STRATEGY]
        ];
        $this->assertEquals($expected, $this->model->toOptionArray());
    }
}
