<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\MessageQueue\Test\Unit\Consumer\Config\Xml;

use Magento\Framework\Communication\Config\ConfigParser;
use Magento\Framework\MessageQueue\Consumer\Config\Xml\Converter;

class ConverterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Converter
     */
    private $converter;

    /**
     * @var ConfigParser|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $configParserMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $defaultConfigProviderMock;

    /**
     * Initialize parameters
     */
    protected function setUp(): void
    {
        $this->defaultConfigProviderMock =
            $this->createMock(\Magento\Framework\MessageQueue\DefaultValueProvider::class);
        $this->configParserMock = $this->createMock(ConfigParser::class);
        $this->converter = new Converter($this->configParserMock, $this->defaultConfigProviderMock);
    }

    public function testConvert()
    {
        $this->defaultConfigProviderMock->expects($this->any())->method('getConnection')->willReturn('amqp');
        $this->configParserMock->expects($this->any())->method('parseServiceMethod')->willReturnCallback(
            function ($handler) {
                $parsedHandler = explode('::', $handler);
                return ['typeName' => $parsedHandler[0], 'methodName' => $parsedHandler[1]];
            }
        );
        $fixtureDir = __DIR__ . '/../../../_files/queue_consumer';
        $xmlFile = $fixtureDir . '/valid.xml';
        $dom = new \DOMDocument();
        $dom->load($xmlFile);
        $result = $this->converter->convert($dom);

        $expectedData = include($fixtureDir . '/valid.php');
        $this->assertEquals($expectedData, $result);
    }
}
