<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\App\Test\Unit\Config\Scope;

class ConverterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\App\Config\Scope\Converter
     */
    protected $_model;

    protected function setUp(): void
    {
        $this->_model = new \Magento\Framework\App\Config\Scope\Converter();
    }

    public function testConvert()
    {
        $data = ['some/config/path1' => 'value1', 'some/config/path2' => 'value2'];
        $expectedResult = ['some' => ['config' => ['path1' => 'value1', 'path2' => 'value2']]];
        $this->assertEquals($expectedResult, $this->_model->convert($data));
    }
}
