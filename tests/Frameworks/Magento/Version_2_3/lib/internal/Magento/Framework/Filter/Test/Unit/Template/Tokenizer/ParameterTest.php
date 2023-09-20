<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Filter\Test\Unit\Template\Tokenizer;

use \Magento\Framework\Filter\Template\Tokenizer\Parameter;

class ParameterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Filter\Template\Tokenizer\Parameter
     */
    protected $_filter;

    protected function setUp(): void
    {
        $this->_filter = new Parameter();
    }

    /**
     * @param string $string String to tokenize
     * @param string $expectedValue
     * @dataProvider sampleTokenizeStringProvider
     */
    public function testTokenize($string, $expectedValue)
    {
        $this->_filter->setString($string);
        $this->assertEquals($expectedValue, $this->_filter->tokenize());
    }

    /**
     * @param string $string String to get value of
     * @param string $expectedValue
     * @dataProvider sampleGetValueStringProvider
     */
    public function testGetValue($string, $expectedValue)
    {
        $this->_filter->setString($string);
        $this->assertEquals($expectedValue, $this->_filter->getValue());
    }

    /**
     * @return array
     */
    public function sampleTokenizeStringProvider()
    {
        return [
            ["%20direct_url='about-magento-demo-store'", ['direct_url' => 'about-magento-demo-store']],
            [" direct_url='about-magento-demo-store\\[newDemo]",
            ['direct_url' => 'about-magento-demo-store\\[newDemo]']],
            ["   ", []]
        ];
    }

    /**
     * @return array
     */
    public function sampleGetValueStringProvider()
    {
        return [
            [" direct_url='about-magento-demo-store'", "direct_url='about-magento-demo-store'"],
            [" direct_url='about-magento-demo-store\\[newDemo]", "direct_url='about-magento-demo-store\\[newDemo]"],
            ['   ', '']
        ];
    }
}
