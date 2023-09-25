<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Eav\Test\Unit\Model\Entity\Attribute\Config;

class ConverterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Eav\Model\Entity\Attribute\Config\Converter
     */
    protected $_model;

    /**
     * Path to files
     *
     * @var string
     */
    protected $_filePath;

    protected function setUp(): void
    {
        $this->_model = new \Magento\Eav\Model\Entity\Attribute\Config\Converter();
        $this->_filePath = realpath(__DIR__) . '/_files/';
    }

    public function testConvert()
    {
        $dom = new \DOMDocument();
        $path = $this->_filePath . 'eav_attributes.xml';
        $dom->load($path);
        $expectedData = include $this->_filePath . 'eav_attributes.php';
        $this->assertEquals($expectedData, $this->_model->convert($dom));
    }
}
