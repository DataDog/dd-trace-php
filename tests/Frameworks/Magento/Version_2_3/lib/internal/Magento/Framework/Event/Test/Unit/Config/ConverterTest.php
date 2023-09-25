<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Event\Test\Unit\Config;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Framework\Event\Config\Converter;

class ConverterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Converter
     */
    protected $model;

    /**
     * @var string
     */
    protected $filePath;

    /**
     * @var \DOMDocument
     */
    protected $source;

    /**
     * @var ObjectManagerHelper
     */
    protected $objectManagerHelper;

    protected function setUp(): void
    {
        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->filePath = __DIR__ . '/_files/';
        $this->source = new \DOMDocument();
        $this->model = $this->objectManagerHelper->getObject(Converter::class);
    }

    public function testConvert()
    {
        $this->source->loadXML(file_get_contents($this->filePath . 'event_config.xml'));
        $convertedFile = include $this->filePath . 'event_config.php';
        $this->assertEquals($convertedFile, $this->model->convert($this->source));
    }

    /**
     */
    public function testConvertThrowsExceptionWhenDomIsInvalid()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Attribute name is missed');

        $this->source->loadXML(file_get_contents($this->filePath . 'event_invalid_config.xml'));
        $this->model->convert($this->source);
    }
}
