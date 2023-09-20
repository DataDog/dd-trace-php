<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Signifyd\Test\Unit\Model\PaymentMethodMapper;

use Magento\Signifyd\Model\PaymentMethodMapper\XmlToArrayConfigConverter;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\Config\Dom\ValidationSchemaException;

class XmlToArrayConfigConverterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var XmlToArrayConfigConverter
     */
    private $converter;

    /**
     * @var string
     */
    private $filePath;

    protected function setUp(): void
    {
        $this->filePath = realpath(__DIR__) . '/_files/';

        $objectManagerHelper = new ObjectManager($this);
        $this->converter = $objectManagerHelper->getObject(
            XmlToArrayConfigConverter::class
        );
    }

    public function testConvert()
    {
        $testDom = $this->filePath . 'signifyd_payment_mapping.xml';
        $dom = new \DOMDocument();
        $dom->load($testDom);
        $mapping = $this->converter->convert($dom);
        $expectedArray = include $this->filePath . 'expected_array.php';

        $this->assertEquals($expectedArray, $mapping);
    }

    /**
     */
    public function testConvertEmptyPaymentMethodException()
    {
        $this->expectException(\Magento\Framework\Config\Dom\ValidationSchemaException::class);
        $this->expectExceptionMessage('Only single entrance of "magento_code" node is required.');

        $dom = new \DOMDocument();
        $element = $dom->createElement('payment_method');
        $subelement = $dom->createElement('signifyd_code', 'test');
        $element->appendChild($subelement);
        $dom->appendChild($element);

        $this->converter->convert($dom);
    }

    /**
     */
    public function testConvertEmptySygnifydPaymentMethodException()
    {
        $this->expectException(\Magento\Framework\Config\Dom\ValidationSchemaException::class);
        $this->expectExceptionMessage('Not empty value for "signifyd_code" node is required.');

        $dom = new \DOMDocument();
        $element = $dom->createElement('payment_method');
        $subelement = $dom->createElement('magento_code', 'test');
        $subelement2 = $dom->createElement('signifyd_code', '');
        $element->appendChild($subelement);
        $element->appendChild($subelement2);
        $dom->appendChild($element);

        $this->converter->convert($dom);
    }
}
