<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Dhl\Test\Unit\Model\Validator;

use Magento\Dhl\Model\Validator\ResponseErrorProcessor;
use Magento\Dhl\Model\Validator\XmlValidator;
use Magento\Framework\Phrase;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\Xml\Security;
use Magento\Sales\Exception\DocumentValidationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class XmlValidatorTest extends TestCase
{
    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var Security|MockObject
     */
    private $xmlSecurityMock;

    /**
     * @var ResponseErrorProcessor|MockObject
     */
    private $errorProcessorMock;

    /**
     * @var XmlValidator
     */
    private $xmlValidator;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);

        // Mock XML Security object
        $this->xmlSecurityMock = $this->getMockBuilder(Security::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->errorProcessorMock = $this->getMockBuilder(ResponseErrorProcessor::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->xmlValidator = $this->objectManager->getObject(
            XmlValidator::class,
            [
                'xmlSecurity' => $this->xmlSecurityMock,
                'errorProcessor' => $this->errorProcessorMock,
            ]
        );
    }

    /**
     * Tests validate() on a valid XML response
     */
    public function testValidateValidXml()
    {
        $rawXml = file_get_contents(__DIR__ . '/_files/validDHLResponse.xml');
        $this->xmlSecurityMock->expects($this->once())->method('scan')->with($rawXml)->willReturn(true);

        try {
            $this->xmlValidator->validate($rawXml);
        } catch (DocumentValidationException $exception) {
            $this->fail($exception->getMessage());
        }
    }

    /**
     * Tests validate() on an invalid XML response
     *
     * @param array $data
     * @dataProvider invalidXmlResponseProvider
     */
    public function testValidateInvalidXml($data)
    {
        $phrase = new Phrase('Error #%1 : %2', ['111', 'Error in parsing request XML']);
        $rawXml = file_get_contents(__DIR__ . '/_files/' . $data['file']);
        $this->xmlSecurityMock->expects($this->any())
            ->method('scan')
            ->with($rawXml)
            ->willReturn($data['isGenerateXml']);
        $this->errorProcessorMock->expects($this->any())
            ->method('process')
            ->willReturn($phrase);

        try {
            $this->xmlValidator->validate($rawXml);
        } catch (DocumentValidationException $exception) {
            $this->assertEquals($data['errorMessage'], $exception->getMessage());
            if (isset($data['code'])) {
                $this->assertEquals($data['code'], $exception->getCode());
            }
            return;
        }

        $this->fail('Exception not thrown for testValidateInvalidXml');
    }

    /**
     * @return array
     */
    public function invalidXmlResponseProvider()
    {
        return [
            [
                [
                    'file' => 'invalidDHLResponseWithMissingXmlTag.xml',
                    'errorMessage' => 'The response is in the wrong format',
                    'isGenerateXml' => false,
                ],
            ],
            [
                [
                    'file' => 'invalidDHLResponse.xml',
                    'errorMessage' => 'The security validation of the XML document has failed.',
                    'isGenerateXml' => false,
                ],
            ],
            [
                [
                    'file' => 'invalidDHLResponse.xml',
                    'errorMessage' => 'Error #111 : Error in parsing request XML',
                    'isGenerateXml' => true,
                    'code' => 111,
                ],
            ],

        ];
    }
}
