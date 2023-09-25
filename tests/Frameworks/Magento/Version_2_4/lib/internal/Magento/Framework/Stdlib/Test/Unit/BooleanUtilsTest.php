<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Stdlib\Test\Unit;

use Magento\Framework\Stdlib\BooleanUtils;
use PHPUnit\Framework\TestCase;

class BooleanUtilsTest extends TestCase
{
    /**
     * @var BooleanUtils
     */
    protected $object;

    protected function setUp(): void
    {
        $this->object = new BooleanUtils();
    }

    public function testConstructor()
    {
        $object = new BooleanUtils(['yep'], ['nope']);
        $this->assertTrue($object->toBoolean('yep'));
        $this->assertFalse($object->toBoolean('nope'));
    }

    /**
     * @param mixed $input
     * @param bool $expected
     *
     * @dataProvider toBooleanDataProvider
     */
    public function testToBoolean($input, $expected)
    {
        $actual = $this->object->toBoolean($input);
        $this->assertSame($expected, $actual);
    }

    /**
     * @return array
     */
    public function toBooleanDataProvider()
    {
        return [
            'boolean "true"' => [true, true],
            'boolean "false"' => [false, false],
            'boolean string "true"' => ['true', true],
            'boolean string "false"' => ['false', false],
            'boolean numeric "1"' => [1, true],
            'boolean numeric "0"' => [0, false],
            'boolean string "1"' => ['1', true],
            'boolean string "0"' => ['0', false]
        ];
    }

    /**
     * @param mixed $input
     *
     * @dataProvider toBooleanExceptionDataProvider
     */
    public function testToBooleanException($input)
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage('Boolean value is expected');
        $this->object->toBoolean($input);
    }

    /**
     * @return array
     */
    public function toBooleanExceptionDataProvider()
    {
        return [
            'boolean string "on"' => ['on'],
            'boolean string "off"' => ['off'],
            'boolean string "yes"' => ['yes'],
            'boolean string "no"' => ['no'],
            'boolean string "TRUE"' => ['TRUE'],
            'boolean string "FALSE"' => ['FALSE'],
            'empty string' => [''],
            'null' => [null]
        ];
    }
}
