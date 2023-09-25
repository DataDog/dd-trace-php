<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Math\Test\Unit;

class CalculatorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Math\Calculator
     */
    protected $_model;

    /**
     * @var \PHPUnit\Framework_MockObject
     */
    protected $priceCurrency;

    protected function setUp(): void
    {
        $this->priceCurrency = $this->getMockBuilder(
            \Magento\Framework\Pricing\PriceCurrencyInterface::class
        )->getMock();
        $this->priceCurrency->expects($this->any())
            ->method('round')
            ->willReturnCallback(function ($argument) {
                return round($argument, 2);
            });

        $this->_model = new \Magento\Framework\Math\Calculator($this->priceCurrency);
    }

    /**
     * @param float $price
     * @param bool $negative
     * @param float $expected
     * @dataProvider deltaRoundDataProvider
     * @covers \Magento\Framework\Math\Calculator::deltaRound
     * @covers \Magento\Framework\Math\Calculator::__construct
     */
    public function testDeltaRound($price, $negative, $expected)
    {
        $this->assertEquals($expected, $this->_model->deltaRound($price, $negative));
    }

    /**
     * @return array
     */
    public function deltaRoundDataProvider()
    {
        return [
            [0, false, 0],
            [2.223, false, 2.22],
            [2.226, false, 2.23],
            [2.226, true, 2.23],
        ];
    }
}
