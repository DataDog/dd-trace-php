<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Ui\Test\Unit\Config\Converter;

use Magento\Ui\Config\Converter\Composite;
use Magento\Ui\Config\ConverterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CompositeTest extends TestCase
{
    /**
     * @var ConverterInterface|MockObject
     */
    private $converter;

    protected function setUp(): void
    {
        $this->converter = $this->getMockBuilder(ConverterInterface::class)
            ->getMockForAbstractClass();
    }

    public function testConvert()
    {
        $expectedResult = ['converted config'];
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $element = $dom->createElement('name');
        $dom->appendChild($element);

        $composite = new Composite(['key' => $this->converter], 'type');
        $this->converter->expects($this->once())
            ->method('convert')
            ->with($element)
            ->willReturn($expectedResult);
        $this->assertEquals($expectedResult, $composite->convert($element, ['type' => 'key']));
    }

    /**
     * @return void
     */
    public function testConvertWithMissedConverter()
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage('Argument converter named \'missedKey\' has not been defined.');
        $element = new \DOMElement('name');
        $composite = new Composite(['key' => $this->converter], 'type');
        $composite->convert($element, ['type' => 'missedKey']);
    }

    /**
     * @return void
     */
    public function testConvertWithInvalidConverter()
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage('Converter named \'key\' is expected to be an argument converter instance.');
        $element = new \DOMElement('name');
        $std = new \stdClass();
        $composite = new Composite(['key' => $std], 'type');
        $composite->convert($element, ['type' => 'key']);
    }
}
