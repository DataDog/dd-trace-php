<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Reflection\Test\Unit;

use Magento\Framework\Reflection\FieldNamer;

/**
 * Field namer Test
 */
class FieldNamerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var FieldNamer
     */
    private $model;

    /**
     * Set up helper.
     */
    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->model = $objectManager->getObject(\Magento\Framework\Reflection\FieldNamer::class);
    }

    /**
     * @param string $methodName
     * @param string $expectedName
     * @dataProvider methodNameProvider
     */
    public function testGetFieldNameForMethodName($methodName, $expectedName)
    {
        $value = $this->model->getFieldNameForMethodName($methodName);
        $this->assertEquals($value, $expectedName);
    }

    /**
     * @return array
     */
    public function methodNameProvider()
    {
        return [
            'isMethod' => ['isValid', 'valid'],
            'getMethod' => ['getValue', 'value'],
            'hasMethod' => ['hasStuff', 'stuff'],
            'randomMethod' => ['randomMethod', null],
        ];
    }
}
