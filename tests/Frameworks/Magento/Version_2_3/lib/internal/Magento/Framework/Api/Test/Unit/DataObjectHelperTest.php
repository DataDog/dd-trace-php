<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Api\Test\Unit;

use Magento\Framework\Api\CustomAttributesDataInterface;
use Magento\Framework\Api\AttributeInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DataObjectHelperTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Api\DataObjectHelper
     */
    protected $dataObjectHelper;

    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManager;

    /**
     * @var \Magento\Framework\Api\ObjectFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectFactoryMock;

    /**
     * @var \Magento\Framework\Reflection\TypeProcessor
     */
    protected $typeProcessor;

    /**
     * @var \Magento\Framework\Reflection\DataObjectProcessor|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectProcessorMock;

    /**
     * @var \Magento\Framework\Api\AttributeValueFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $attributeValueFactoryMock;

    /**
     * @var \Magento\Framework\Reflection\MethodsMap|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $methodsMapProcessor;

    /**
     * @var \Magento\Framework\Api\ExtensionAttribute\JoinProcessor|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $joinProcessorMock;

    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->objectFactoryMock = $this->getMockBuilder(\Magento\Framework\Api\ObjectFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->objectProcessorMock = $this->getMockBuilder(\Magento\Framework\Reflection\DataObjectProcessor::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->methodsMapProcessor = $this->getMockBuilder(\Magento\Framework\Reflection\MethodsMap::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->attributeValueFactoryMock = $this->getMockBuilder(\Magento\Framework\Api\AttributeValueFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->joinProcessorMock = $this->getMockBuilder(\Magento\Framework\Api\ExtensionAttribute\JoinProcessor::class)
            ->setMethods(['extractExtensionAttributes'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->joinProcessorMock->expects($this->any())
            ->method('extractExtensionAttributes')
            ->willReturnArgument(1);
        $this->typeProcessor = $this->objectManager->getObject(\Magento\Framework\Reflection\TypeProcessor::class);

        $this->dataObjectHelper = $this->objectManager->getObject(
            \Magento\Framework\Api\DataObjectHelper::class,
            [
                'objectFactory' => $this->objectFactoryMock,
                'typeProcessor' => $this->typeProcessor,
                'objectProcessor' => $this->objectProcessorMock,
                'methodsMapProcessor' => $this->methodsMapProcessor,
                'joinProcessor' => $this->joinProcessorMock
            ]
        );
    }

    public function testPopulateWithArrayWithSimpleAttributes()
    {
        $id = 5;
        $countryId = 15;
        $street = ["7700 W Parmer Lane", "second line"];
        $isDefaultShipping = true;

        $regionId = 7;
        $region = "TX";

        /** @var \Magento\Customer\Model\Data\Address $addressDataObject */
        $addressDataObject = $this->objectManager->getObject(
            \Magento\Customer\Model\Data\Address::class,
            [
                'dataObjectHelper' => $this->dataObjectHelper,
            ]
        );

        /** @var \Magento\Customer\Model\Data\Region $regionDataObject */
        $regionDataObject = $this->objectManager->getObject(
            \Magento\Customer\Model\Data\Region::class,
            [
                'dataObjectHelper' => $this->dataObjectHelper,
            ]
        );
        $data = [
            'id' => $id,
            'country_id' => $countryId,
            'street' => $street,
            'default_shipping' => $isDefaultShipping,
            'region' => [
                'region_id' => $regionId,
                'region' => $region,
            ],
        ];

        $this->methodsMapProcessor->expects($this->at(0))
            ->method('getMethodReturnType')
            ->with(\Magento\Customer\Api\Data\AddressInterface::class, 'getStreet')
            ->willReturn('string[]');
        $this->methodsMapProcessor->expects($this->at(1))
            ->method('getMethodReturnType')
            ->with(\Magento\Customer\Api\Data\AddressInterface::class, 'getRegion')
            ->willReturn(\Magento\Customer\Api\Data\RegionInterface::class);
        $this->objectFactoryMock->expects($this->once())
            ->method('create')
            ->with(\Magento\Customer\Api\Data\RegionInterface::class, [])
            ->willReturn($regionDataObject);

        $this->dataObjectHelper->populateWithArray(
            $addressDataObject,
            $data,
            \Magento\Customer\Api\Data\AddressInterface::class
        );

        $this->assertEquals($id, $addressDataObject->getId());
        $this->assertEquals($countryId, $addressDataObject->getCountryId());
        $this->assertEquals($street, $addressDataObject->getStreet());
        $this->assertEquals($isDefaultShipping, $addressDataObject->isDefaultShipping());
        $this->assertEquals($region, $addressDataObject->getRegion()->getRegion());
        $this->assertEquals($regionId, $addressDataObject->getRegion()->getRegionId());
    }

    public function testPopulateWithArrayWithCustomAttribute()
    {
        $id = 5;

        $customAttributeCode = 'custom_attribute_code_1';
        $customAttributeValue = 'custom_attribute_value_1';

        $attributeMetaDataMock = $this->getMockBuilder(\Magento\Customer\Api\Data\AttributeMetadataInterface::class)
            ->getMock();
        $attributeMetaDataMock->expects($this->once())
            ->method('getAttributeCode')
            ->willReturn($customAttributeCode);
        $metadataServiceMock = $this->getMockBuilder(\Magento\Customer\Model\Metadata\AddressMetadata::class)
            ->disableOriginalConstructor()
            ->getMock();
        $metadataServiceMock->expects($this->once())
            ->method('getCustomAttributesMetadata')
            ->with(\Magento\Customer\Model\Data\Address::class)
            ->willReturn(
                [$attributeMetaDataMock]
            );

        /** @var \Magento\Customer\Model\Data\Address $addressDataObject */
        $addressDataObject = $this->objectManager->getObject(
            \Magento\Customer\Model\Data\Address::class,
            [
                'dataObjectHelper' => $this->dataObjectHelper,
                'metadataService' => $metadataServiceMock,
                'attributeValueFactory' => $this->attributeValueFactoryMock,
            ]
        );

        $data = [
            'id' => $id,
            $customAttributeCode => $customAttributeValue,
        ];

        $customAttribute = $this->objectManager->getObject(\Magento\Framework\Api\AttributeValue::class);
        $this->attributeValueFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($customAttribute);
        $this->dataObjectHelper->populateWithArray(
            $addressDataObject,
            $data,
            \Magento\Customer\Api\Data\AddressInterface::class
        );

        $this->assertEquals($id, $addressDataObject->getId());
        $this->assertEquals(
            $customAttributeValue,
            $addressDataObject->getCustomAttribute($customAttributeCode)->getValue()
        );
        $this->assertEquals(
            $customAttributeCode,
            $addressDataObject->getCustomAttribute($customAttributeCode)->getAttributeCode()
        );
    }

    public function testPopulateWithArrayWithCustomAttributes()
    {
        $id = 5;

        $customAttributeCode = 'custom_attribute_code_1';
        $customAttributeValue = 'custom_attribute_value_1';

        $attributeMetaDataMock = $this->getMockBuilder(\Magento\Customer\Api\Data\AttributeMetadataInterface::class)
            ->getMock();
        $attributeMetaDataMock->expects($this->once())
            ->method('getAttributeCode')
            ->willReturn($customAttributeCode);
        $metadataServiceMock = $this->getMockBuilder(\Magento\Customer\Model\Metadata\AddressMetadata::class)
            ->disableOriginalConstructor()
            ->getMock();
        $metadataServiceMock->expects($this->once())
            ->method('getCustomAttributesMetadata')
            ->with(\Magento\Customer\Model\Data\Address::class)
            ->willReturn(
                [$attributeMetaDataMock]
            );

        /** @var \Magento\Customer\Model\Data\Address $addressDataObject */
        $addressDataObject = $this->objectManager->getObject(
            \Magento\Customer\Model\Data\Address::class,
            [
                'dataObjectHelper' => $this->dataObjectHelper,
                'metadataService' => $metadataServiceMock,
                'attributeValueFactory' => $this->attributeValueFactoryMock,
            ]
        );

        $data = [
            'id' => $id,
            CustomAttributesDataInterface::CUSTOM_ATTRIBUTES => [
                [
                    AttributeInterface::ATTRIBUTE_CODE => $customAttributeCode,
                    AttributeInterface::VALUE => $customAttributeValue,
                ],
            ],
        ];

        $customAttribute = $this->objectManager->getObject(\Magento\Framework\Api\AttributeValue::class);
        $this->attributeValueFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($customAttribute);
        $this->dataObjectHelper->populateWithArray(
            $addressDataObject,
            $data,
            \Magento\Customer\Api\Data\AddressInterface::class
        );

        $this->assertEquals($id, $addressDataObject->getId());
        $this->assertEquals(
            $customAttributeValue,
            $addressDataObject->getCustomAttribute($customAttributeCode)->getValue()
        );
        $this->assertEquals(
            $customAttributeCode,
            $addressDataObject->getCustomAttribute($customAttributeCode)->getAttributeCode()
        );
    }

    /**
     * @param array $data1
     * @param array $data2
     * @dataProvider dataProviderForTestMergeDataObjects
     */
    public function testMergeDataObjects($data1, $data2)
    {
        /** @var \Magento\Customer\Model\Data\Address $addressDataObject */
        $firstAddressDataObject = $this->objectManager->getObject(
            \Magento\Customer\Model\Data\Address::class,
            [
                'dataObjectHelper' => $this->dataObjectHelper,
            ]
        );

        /** @var \Magento\Customer\Model\Data\Region $regionDataObject */
        $firstRegionDataObject = $this->objectManager->getObject(
            \Magento\Customer\Model\Data\Region::class,
            [
                'dataObjectHelper' => $this->dataObjectHelper,
            ]
        );

        $firstRegionDataObject->setRegionId($data1['region']['region_id']);
        $firstRegionDataObject->setRegion($data1['region']['region']);
        if (isset($data1['id'])) {
            $firstAddressDataObject->setId($data1['id']);
        }
        if (isset($data1['country_id'])) {
            $firstAddressDataObject->setCountryId($data1['country_id']);
        }
        $firstAddressDataObject->setStreet($data1['street']);
        $firstAddressDataObject->setIsDefaultShipping($data1['default_shipping']);
        $firstAddressDataObject->setRegion($firstRegionDataObject);

        $secondAddressDataObject = $this->objectManager->getObject(
            \Magento\Customer\Model\Data\Address::class,
            [
                'dataObjectHelper' => $this->dataObjectHelper,
            ]
        );

        /** @var \Magento\Customer\Model\Data\Region $regionDataObject */
        $secondRegionDataObject = $this->objectManager->getObject(
            \Magento\Customer\Model\Data\Region::class,
            [
                'dataObjectHelper' => $this->dataObjectHelper,
            ]
        );

        $secondRegionDataObject->setRegionId($data2['region']['region_id']);
        $secondRegionDataObject->setRegion($data2['region']['region']);
        if (isset($data2['id'])) {
            $secondAddressDataObject->setId($data2['id']);
        }
        if (isset($data2['country_id'])) {
            $secondAddressDataObject->setCountryId($data2['country_id']);
        }
        $secondAddressDataObject->setStreet($data2['street']);
        $secondAddressDataObject->setIsDefaultShipping($data2['default_shipping']);
        $secondAddressDataObject->setRegion($secondRegionDataObject);

        $this->objectProcessorMock->expects($this->once())
            ->method('buildOutputDataArray')
            ->with($secondAddressDataObject, get_class($firstAddressDataObject))
            ->willReturn($data2);
        $this->methodsMapProcessor->expects($this->at(0))
            ->method('getMethodReturnType')
            ->with(\Magento\Customer\Model\Data\Address::class, 'getStreet')
            ->willReturn('string[]');
        $this->methodsMapProcessor->expects($this->at(1))
            ->method('getMethodReturnType')
            ->with(\Magento\Customer\Model\Data\Address::class, 'getRegion')
            ->willReturn(\Magento\Customer\Api\Data\RegionInterface::class);
        $this->objectFactoryMock->expects($this->once())
            ->method('create')
            ->with(\Magento\Customer\Api\Data\RegionInterface::class, [])
            ->willReturn($secondRegionDataObject);

        $this->dataObjectHelper->mergeDataObjects(
            get_class($firstAddressDataObject),
            $firstAddressDataObject,
            $secondAddressDataObject
        );

        $this->assertSame($firstAddressDataObject->getId(), $secondAddressDataObject->getId());
        $this->assertSame($firstAddressDataObject->getCountryId(), $secondAddressDataObject->getCountryId());
        $this->assertSame($firstAddressDataObject->getStreet(), $secondAddressDataObject->getStreet());
        $this->assertSame($firstAddressDataObject->isDefaultShipping(), $secondAddressDataObject->isDefaultShipping());
        $this->assertSame($firstAddressDataObject->getRegion(), $secondAddressDataObject->getRegion());
    }

    /**
     * @return array
     */
    public function dataProviderForTestMergeDataObjects()
    {
        return [
            [
                [
                    'id' => '1',
                    'country_id' => '1',
                    'street' => ["7701 W Parmer Lane", "Second Line"],
                    'default_shipping' => true,
                    'region' => [
                        'region_id' => '1',
                        'region' => 'TX',
                    ]
                ],
                [
                    'id' => '2',
                    'country_id' => '2',
                    'street' => ["7702 W Parmer Lane", "Second Line"],
                    'default_shipping' => false,
                    'region' => [
                        'region_id' => '2',
                        'region' => 'TX',
                    ]
                ]
            ],
            [
                [
                    'street' => ["7701 W Parmer Lane", "Second Line"],
                    'default_shipping' => true,
                    'region' => [
                        'region_id' => '1',
                        'region' => 'TX',
                    ]
                ],
                [
                    'id' => '2',
                    'country_id' => '2',
                    'street' => ["7702 W Parmer Lane", "Second Line"],
                    'default_shipping' => false,
                    'region' => [
                        'region_id' => '2',
                        'region' => 'TX',
                    ]
                ]
            ]
        ];
    }
}
