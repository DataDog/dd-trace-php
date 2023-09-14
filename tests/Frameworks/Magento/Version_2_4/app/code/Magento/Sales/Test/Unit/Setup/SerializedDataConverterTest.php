<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Sales\Test\Unit\Setup;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Serialize\Serializer\Serialize;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Sales\Setup\SerializedDataConverter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SerializedDataConverterTest extends TestCase
{
    /**
     * @var Serialize|MockObject
     */
    private $serializeMock;

    /**
     * @var Json|MockObject
     */
    private $jsonMock;

    /**
     * @var SerializedDataConverter
     */
    private $serializedDataConverter;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);
        $this->serializeMock = $this->createMock(Serialize::class);
        $this->jsonMock = $this->createMock(Json::class);
        $this->serializedDataConverter = $objectManager->getObject(
            SerializedDataConverter::class,
            [
                'serialize' => $this->serializeMock,
                'json' => $this->jsonMock
            ]
        );
    }

    /**
     * @return void
     */
    public function testConvert(): void
    {
        $serializedData = 'serialized data';
        $jsonEncodedData = 'json encoded data';
        $data = [
            'info_buyRequest' => [
                'product' => 1,
                'qty' => 2
            ]
        ];
        $this->serializeMock->expects($this->once())
            ->method('unserialize')
            ->with($serializedData)
            ->willReturn($data);
        $this->jsonMock->expects($this->once())
            ->method('serialize')
            ->with($data)
            ->willReturn($jsonEncodedData);
        $this->assertEquals(
            $jsonEncodedData,
            $this->serializedDataConverter->convert($serializedData)
        );
    }

    /**
     * @return void
     */
    public function testConvertBundleAttributes(): void
    {
        $serializedData = 'serialized data';
        $serializedBundleAttributes = 'serialized bundle attributes';
        $bundleAttributes = ['foo' => 'bar'];
        $jsonEncodedBundleAttributes = 'json encoded bundle attributes';
        $jsonEncodedData = 'json encoded data';
        $data = [
            'info_buyRequest' => [
                'product' => 1,
                'qty' => 2
            ],
            'bundle_selection_attributes' => $serializedBundleAttributes
        ];
        $dataWithJsonEncodedBundleAttributes = [
            'info_buyRequest' => [
                'product' => 1,
                'qty' => 2
            ],
            'bundle_selection_attributes' => $jsonEncodedBundleAttributes
        ];
        $this->serializeMock
            ->method('unserialize')
            ->withConsecutive([$serializedData], [$serializedBundleAttributes])
            ->willReturnOnConsecutiveCalls($data, $bundleAttributes);
        $this->jsonMock
            ->method('serialize')
            ->withConsecutive([$bundleAttributes], [$dataWithJsonEncodedBundleAttributes])
            ->willReturnOnConsecutiveCalls($jsonEncodedBundleAttributes, $jsonEncodedData);
        $this->assertEquals(
            $jsonEncodedData,
            $this->serializedDataConverter->convert($serializedData)
        );
    }

    /**
     * @return void
     */
    public function testConvertCustomOptionsTypeFile(): void
    {
        $serializedData = 'serialized data';
        $serializedOptionValue = 'serialized option value';
        $optionValue = ['foo' => 'bar'];
        $jsonEncodedOptionValue = 'json encoded option value';
        $jsonEncodedData = 'json encoded data';
        $data = [
            'info_buyRequest' => [
                'product' => 1,
                'qty' => 2
            ],
            'options' => [
                [
                    'option_type' => 'file',
                    'option_value' => $serializedOptionValue
                ],
                [
                    'option_type' => 'text',
                    'option_value' => 'option 2'
                ]
            ]
        ];
        $dataWithJsonEncodedOptionValue = [
            'info_buyRequest' => [
                'product' => 1,
                'qty' => 2
            ],
            'options' => [
                [
                    'option_type' => 'file',
                    'option_value' => $jsonEncodedOptionValue
                ],
                [
                    'option_type' => 'text',
                    'option_value' => 'option 2'
                ]
            ]
        ];
        $this->serializeMock
            ->method('unserialize')
            ->withConsecutive([$serializedData], [$serializedOptionValue])
            ->willReturnOnConsecutiveCalls($data, $optionValue);
        $this->jsonMock
            ->method('serialize')
            ->withConsecutive([$optionValue], [$dataWithJsonEncodedOptionValue])
            ->willReturnOnConsecutiveCalls($jsonEncodedOptionValue, $jsonEncodedData);
        $this->assertEquals(
            $jsonEncodedData,
            $this->serializedDataConverter->convert($serializedData)
        );
    }

    /**
     * @return void
     */
    public function testConvertCorruptedData(): void
    {
        $this->expectException('Magento\Framework\DB\DataConverter\DataConversionException');
        $this->serializeMock->expects($this->once())
            ->method('unserialize')
            ->willReturnCallback(
                function () {
                    trigger_error('Can not unserialize string message', E_NOTICE);
                }
            );
        $this->serializedDataConverter->convert('serialized data');
    }

    /**
     * @return void
     */
    public function testConvertSkipConversion(): void
    {
        $serialized = '[]';
        $this->serializeMock->expects($this->never())
            ->method('unserialize');
        $this->jsonMock->expects($this->never())
            ->method('serialize');
        $this->serializedDataConverter->convert($serialized);
    }
}
