<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Test\Unit\Model\Product\Webapi\Rest;

use Magento\Catalog\Model\Product\Webapi\Rest\RequestTypeBasedDeserializer;
use Magento\Framework\Webapi\Rest\Request\DeserializerFactory;
use Magento\Framework\Webapi\Rest\Request;
use PHPUnit\Framework\MockObject\MockObject;
use Magento\Framework\Webapi\Rest\Request\DeserializerInterface;
use Magento\Framework\Webapi\Rest\Request\Deserializer\Json as DeserializerJson;
use Magento\Framework\Webapi\Rest\Request\Deserializer\Xml as DeserializerXml;
use Magento\Framework\App\State;
use Magento\Framework\Json\Decoder;
use Magento\Framework\Serialize\Serializer\Json as SerializerJson;
use Magento\Framework\Xml\Parser as ParserXml;

class RequestTypeBasedDeserializerTest extends \PHPUnit\Framework\TestCase
{
    /** @var RequestTypeBasedDeserializer */
    private $requestTypeBasedDeserializer;
    /**
     * @var DeserializerFactory|MockObject
     */
    private $deserializeFactoryMock;

    /**
     * @var Request|MockObject
     */
    private $requestMock;

    public function setUp(): void
    {
        /** @var DeserializerFactory|MockObject $deserializeFactoryMock */
        $this->deserializeFactoryMock = $this->createMock(DeserializerFactory::class);
        /** @var Request|MockObject $requestMock */
        $this->requestMock = $this->createMock(Request::class);
        /** @var  requestTypeBasedDeserializer */
        $this->requestTypeBasedDeserializer = new RequestTypeBasedDeserializer(
            $this->deserializeFactoryMock,
            $this->requestMock
        );
    }

    /**
     * Test RequestTypeBasedDeserializer::deserializeMethod()
     *
     * @dataProvider getDeserializerDataProvider
     * @param string $body
     * @param string $contentType
     * @param DeserializerInterface $deserializer
     * @param array $expectedResult
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Webapi\Exception
     */
    public function testDeserialize(
        string $body,
        string $contentType,
        DeserializerInterface $deserializer,
        array $expectedResult
    ): void {
        $this->requestMock->method('getContentType')
            ->willReturn($contentType);
        $this->deserializeFactoryMock->expects($this->any())
            ->method('get')
            ->with($contentType)
            ->willReturn($deserializer);
        $this->assertEquals($expectedResult, $this->requestTypeBasedDeserializer->deserialize($body));
    }

    public function getDeserializerDataProvider(): array
    {
        return [
            'request body with xml data' => [
                'body' => '<products>
	                           <product>
                                   <sku>testSku1</sku>
                                   <name>testName1</name>
                                   <weight>10</weight>
                                   <attribute_set_id>4</attribute_set_id>
                                   <status>1</status>
	                           </product>
                           </products>',
                'content-type' => 'application/xml',
                'deserializer' => $this->prepareXmlDeserializer(),
                'expectedResult' => [
                    'product' => [
                        'sku' => 'testSku1',
                        'name' => 'testName1',
                        'weight' => '10',
                        'attribute_set_id' => '4',
                        'status' => '1'
                    ]
                ]
            ],
            'request body with json data' => [
                'body' => '{
                    "product": {
                        "sku": "testSku2",
                        "name": "testName2",
                        "weight": 5,
                        "attribute_set_id": 4,
                        "status": 0
                    }
                }',
                'content-type' => 'application/json',
                'deserializer' => $this->prepareJsonDeserializer(),
                'expectedResult' => [
                    'product' => [
                        'sku' => 'testSku2',
                        'name' => 'testName2',
                        'weight' => 5,
                        'attribute_set_id' => 4,
                        'status' => 0
                    ]
                ]
            ]
        ];
    }

    /**
     * Creates Json Deserializer instance with some mocked parameters
     *
     * @return DeserializerJson
     */
    private function prepareJsonDeserializer(): DeserializerJson
    {
        /** @var Decoder|MockObject $decoder */
        $decoder = $this->createMock(Decoder::class);
        /** @var State|MockObject $appStateMock */
        $appStateMock = $this->createMock(State::class);
        $serializer =  new SerializerJson();
        return new DeserializerJson($decoder, $appStateMock, $serializer);
    }

    /**
     * Creates XML Deserializer instance with some mocked parameters
     *
     * @return DeserializerXml
     */
    private function prepareXmlDeserializer(): DeserializerXml
    {
        $parserXml = new ParserXml();
        /** @var State|MockObject $appStateMock */
        $appStateMock = $this->createMock(State::class);
        return new DeserializerXml($parserXml, $appStateMock);
    }
}
