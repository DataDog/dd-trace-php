<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Stdlib\Test\Unit;

use Magento\Framework\Stdlib\StringUtils;
use PHPUnit\Framework\TestCase;

/**
 * Magento\Framework\Stdlib\StringUtilsTest test case
 */
class StringUtilsTest extends TestCase
{
    /**
     * @var StringUtils
     */
    protected $_string;

    protected function setUp(): void
    {
        $this->_string = new StringUtils();
    }

    /**
     * @covers \Magento\Framework\Stdlib\StringUtils::split
     */
    public function testStrSplit()
    {
        $this->assertEquals([], $this->_string->split(''));
        $this->assertEquals(['1', '2', '3', '4'], $this->_string->split('1234', 1));
        $this->assertEquals(['1', '2', ' ', '3', '4'], $this->_string->split('12 34', 1, false, true));
        $this->assertEquals(
            ['12345', '123', '12345', '6789'],
            $this->_string->split('12345  123    123456789', 5, true, true)
        );
        $this->assertEquals(
            ['1234', '5', '123', '1234', '5678', '9'],
            $this->_string->split('12345  123    123456789', 4, true, true)
        );
    }

    /**
     * @covers \Magento\Framework\Stdlib\StringUtils::splitInjection
     */
    public function testSplitInjection()
    {
        $string = '1234567890';
        $this->assertEquals('1234 5678 90', $this->_string->splitInjection($string, 4));
    }

    /**
     * @covers \Magento\Framework\Stdlib\StringUtils::cleanString
     */
    public function testCleanString()
    {
        $string = '12345';
        $this->assertEquals($string, $this->_string->cleanString($string));
    }

    public function testSubstr()
    {
        $this->assertSame('tring', $this->_string->substr('string', 1));
    }

    public function testStrrev()
    {
        $this->assertSame('0987654321', $this->_string->strrev('1234567890'));
        $this->assertSame('', $this->_string->strrev(''));
    }

    /**
     * @covers \Magento\Framework\Stdlib\StringUtils::strpos
     */
    public function testStrpos()
    {
        $this->assertEquals(1, $this->_string->strpos('123', 2));
    }

    /**
     * @param string $testString
     * @param string $expected
     *
     * @dataProvider upperCaseWordsDataProvider
     */
    public function testUpperCaseWords($testString, $expected)
    {
        $actual = $this->_string->upperCaseWords($testString);
        $this->assertEquals($expected, $actual);
    }

    /**
     * @return array
     */
    public function upperCaseWordsDataProvider()
    {
        return [
            ['test test2', 'Test_Test2'],
            ['test_test2', 'Test_Test2'],
            ['test_test2 test3', 'Test_Test2_Test3']
        ];
    }

    /**
     * @param string $testString
     * @param string $sourceSeparator
     * @param string $destinationSeparator
     * @param string $expected
     *
     * @dataProvider upperCaseWordsWithSeparatorsDataProvider
     */
    public function testUpperCaseWordsWithSeparators($testString, $sourceSeparator, $destinationSeparator, $expected)
    {
        $actual = $this->_string->upperCaseWords($testString, $sourceSeparator, $destinationSeparator);
        $this->assertEquals($expected, $actual);
    }

    /**
     * @return array
     */
    public function upperCaseWordsWithSeparatorsDataProvider()
    {
        return [['test test2_test3\test4|test5', '|', '\\', 'Test\Test2_test3\test4\Test5']];
    }
}
