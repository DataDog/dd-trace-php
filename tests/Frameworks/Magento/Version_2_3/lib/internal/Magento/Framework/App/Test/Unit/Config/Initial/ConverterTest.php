<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\App\Test\Unit\Config\Initial;

class ConverterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\App\Config\Initial\Converter
     */
    protected $_model;

    protected function setUp(): void
    {
        $nodeMap = [
            'default' => '/config/default',
            'stores' => '/config/stores',
            'websites' => '/config/websites',
        ];
        $this->_model = new \Magento\Framework\App\Config\Initial\Converter($nodeMap);
    }

    public function testConvert()
    {
        $fixturePath = __DIR__ . '/_files/';
        $dom = new \DOMDocument();
        $dom->loadXML(file_get_contents($fixturePath . 'config.xml'));
        $expectedResult = include $fixturePath . 'converted_config.php';
        $this->assertEquals($expectedResult, $this->_model->convert($dom));
    }
}
