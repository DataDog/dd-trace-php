<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\App\Test\Unit\Config\Initial;

class XsdTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Path to xsd schema file
     * @var string
     */
    protected $xsdSchema;

    /**
     * @var \Magento\Framework\TestFramework\Unit\Utility\XsdValidator
     */
    protected $xsdValidator;

    protected function setUp(): void
    {
        if (!function_exists('libxml_set_external_entity_loader')) {
            $this->markTestSkipped('Skipped on HHVM. Will be fixed in MAGETWO-45033');
        }
        $urnResolver = new \Magento\Framework\Config\Dom\UrnResolver();
        $this->xsdSchema = $urnResolver->getRealPath('urn:magento:module:Magento_Store:etc/config.xsd');
        $this->xsdValidator = new \Magento\Framework\TestFramework\Unit\Utility\XsdValidator();
    }

    /**
     * @param string $xmlString
     * @param array $expectedError
     * @dataProvider schemaCorrectlyIdentifiesInvalidXmlDataProvider
     */
    public function testSchemaCorrectlyIdentifiesInvalidXml($xmlString, $expectedError)
    {
        $actualError = $this->xsdValidator->validate($this->xsdSchema, $xmlString);
        $this->assertEquals($expectedError, $actualError);
    }

    public function testSchemaCorrectlyIdentifiesValidXml()
    {
        $xmlString = file_get_contents(__DIR__ . '/_files/valid_config.xml');
        $actualResult = $this->xsdValidator->validate($this->xsdSchema, $xmlString);
        $this->assertEmpty($actualResult);
    }

    /**
     * Data provider with invalid xml array according to config.xsd
     */
    public function schemaCorrectlyIdentifiesInvalidXmlDataProvider()
    {
        return include __DIR__ . '/_files/invalidConfigXmlArray.php';
    }
}
