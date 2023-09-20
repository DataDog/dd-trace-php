<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Mview\Test\Unit\Config;

class ReaderTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Mview\Config\Reader
     */
    protected $_model;

    /**
     * @var \Magento\Framework\Mview\Config\Converter|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_converter;

    /**
     * @var \Magento\Framework\App\Config\FileResolver|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_fileResolverMock;

    protected function setUp(): void
    {
        $this->_fileResolverMock = $this->createPartialMock(\Magento\Framework\App\Config\FileResolver::class, ['get']);

        $this->_converter = $this->createPartialMock(\Magento\Framework\Mview\Config\Converter::class, ['convert']);

        $urnResolverMock = $this->createMock(\Magento\Framework\Config\Dom\UrnResolver::class);
        $urnResolverMock->expects($this->once())
            ->method('getRealPath')
            ->with('urn:magento:framework:Mview/etc/mview.xsd')
            ->willReturn('test_folder');
        $schemaLocator = new \Magento\Framework\Mview\Config\SchemaLocator($urnResolverMock);

        $validationState = $this->createMock(\Magento\Framework\Config\ValidationStateInterface::class);
        $validationState->expects($this->any())
            ->method('isValidationRequired')
            ->willReturn(false);

        $this->_model = new \Magento\Framework\Mview\Config\Reader(
            $this->_fileResolverMock,
            $this->_converter,
            $schemaLocator,
            $validationState
        );
    }

    /**
     * @dataProvider readerDataProvider
     */
    public function testReadValidConfig($files, $expectedFile)
    {
        $this->_fileResolverMock->expects(
            $this->once()
        )->method(
            'get'
        )->with(
            'mview.xml',
            'scope'
        )->willReturn(
            $files
        );

        $constraint = function (\DOMDocument $actual) use ($expectedFile) {
            try {
                $expected = file_get_contents(__DIR__ . '/../_files/' . $expectedFile);
                \PHPUnit\Framework\Assert::assertXmlStringEqualsXmlString($expected, $actual->saveXML());
                return true;
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                return false;
            }
        };
        $expectedResult = new \stdClass();
        $this->_converter->expects(
            $this->once()
        )->method(
            'convert'
        )->with(
            $this->callback($constraint)
        )->willReturn(
            $expectedResult
        );

        $this->assertSame($expectedResult, $this->_model->read('scope'));
    }

    /**
     * @return array
     */
    public function readerDataProvider()
    {
        return [
            'mview_merged_one' => [
                [
                    'mview_one.xml' => file_get_contents(__DIR__ . '/../_files/mview_one.xml'),
                    'mview_two.xml' => file_get_contents(__DIR__ . '/../_files/mview_two.xml'),
                ],
                'mview_merged_one.xml',
            ],
            'mview_merged_two' => [
                [
                    'mview_one.xml' => file_get_contents(__DIR__ . '/../_files/mview_one.xml'),
                    'mview_three.xml' => file_get_contents(__DIR__ . '/../_files/mview_three.xml'),
                ],
                'mview_merged_two.xml',
            ]
        ];
    }
}
