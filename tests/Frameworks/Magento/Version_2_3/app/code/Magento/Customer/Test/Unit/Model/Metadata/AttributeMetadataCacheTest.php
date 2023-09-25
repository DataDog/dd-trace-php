<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Test\Unit\Model\Metadata;

use Magento\Config\App\Config\Type\System;
use Magento\Customer\Api\Data\AttributeMetadataInterface;
use Magento\Customer\Model\Metadata\AttributeMetadataCache;
use Magento\Customer\Model\Metadata\AttributeMetadataHydrator;
use Magento\Eav\Model\Cache\Type;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class AttributeMetadataCacheTest
 * Test for AttributeMetadataCache
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AttributeMetadataCacheTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var CacheInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $cacheMock;

    /**
     * @var StateInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $stateMock;

    /**
     * @var AttributeMetadataHydrator|\PHPUnit\Framework\MockObject\MockObject
     */
    private $attributeMetadataHydratorMock;

    /**
     * @var SerializerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $serializerMock;

    /**
     * @var AttributeMetadataCache|\PHPUnit\Framework\MockObject\MockObject
     */
    private $attributeMetadataCache;

    /**
     * @var StoreInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $storeMock;

    /**
     * @var StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $storeManagerMock;

    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);
        $this->cacheMock = $this->getMockForAbstractClass(CacheInterface::class);
        $this->stateMock = $this->getMockForAbstractClass(StateInterface::class);
        $this->serializerMock = $this->getMockForAbstractClass(SerializerInterface::class);
        $this->attributeMetadataHydratorMock = $this->createMock(AttributeMetadataHydrator::class);
        $this->storeMock = $this->getMockForAbstractClass(StoreInterface::class);
        $this->storeManagerMock = $this->getMockForAbstractClass(StoreManagerInterface::class);
        $this->storeManagerMock->method('getStore')->willReturn($this->storeMock);
        $this->storeMock->method('getId')->willReturn(1);
        $this->attributeMetadataCache = $objectManager->getObject(
            AttributeMetadataCache::class,
            [
                'cache' => $this->cacheMock,
                'state' => $this->stateMock,
                'serializer' => $this->serializerMock,
                'attributeMetadataHydrator' => $this->attributeMetadataHydratorMock,
                'storeManager' => $this->storeManagerMock
            ]
        );
    }

    public function testLoadCacheDisabled()
    {
        $entityType = 'EntityType';
        $suffix = 'none';
        $this->stateMock->expects($this->once())
            ->method('isEnabled')
            ->with(Type::TYPE_IDENTIFIER)
            ->willReturn(false);
        $this->cacheMock->expects($this->never())
            ->method('load');
        $this->assertFalse($this->attributeMetadataCache->load($entityType, $suffix));
        // Make sure isEnabled called once
        $this->attributeMetadataCache->load($entityType, $suffix);
    }

    public function testLoadNoCache()
    {
        $entityType = 'EntityType';
        $suffix = 'none';
        $storeId = 1;
        $cacheKey = AttributeMetadataCache::ATTRIBUTE_METADATA_CACHE_PREFIX . $entityType . $suffix . $storeId;
        $this->stateMock->expects($this->once())
            ->method('isEnabled')
            ->with(Type::TYPE_IDENTIFIER)
            ->willReturn(true);
        $this->cacheMock->expects($this->once())
            ->method('load')
            ->with($cacheKey)
            ->willReturn(false);
        $this->assertFalse($this->attributeMetadataCache->load($entityType, $suffix));
    }

    public function testLoad()
    {
        $entityType = 'EntityType';
        $suffix = 'none';
        $storeId = 1;
        $cacheKey = AttributeMetadataCache::ATTRIBUTE_METADATA_CACHE_PREFIX . $entityType . $suffix . $storeId;
        $serializedString = 'serialized string';
        $attributeMetadataOneData = [
            'attribute_code' => 'attribute_code',
            'frontend_input' => 'hidden',
        ];
        $attributesMetadataData = [$attributeMetadataOneData];
        $this->stateMock->expects($this->once())
            ->method('isEnabled')
            ->with(Type::TYPE_IDENTIFIER)
            ->willReturn(true);
        $this->cacheMock->expects($this->once())
            ->method('load')
            ->with($cacheKey)
            ->willReturn($serializedString);
        $this->serializerMock->expects($this->once())
            ->method('unserialize')
            ->with($serializedString)
            ->willReturn($attributesMetadataData);
        /** @var AttributeMetadataInterface|\PHPUnit\Framework\MockObject\MockObject $attributeMetadataMock */
        $attributeMetadataMock = $this->getMockForAbstractClass(AttributeMetadataInterface::class);
        $this->attributeMetadataHydratorMock->expects($this->at(0))
            ->method('hydrate')
            ->with($attributeMetadataOneData)
            ->willReturn($attributeMetadataMock);
        $attributesMetadata = $this->attributeMetadataCache->load($entityType, $suffix);
        $this->assertIsArray(
            $attributesMetadata
        );
        $this->assertArrayHasKey(
            0,
            $attributesMetadata
        );
        $this->assertInstanceOf(
            AttributeMetadataInterface::class,
            $attributesMetadata[0]
        );
    }

    public function testSaveCacheDisabled()
    {
        $entityType = 'EntityType';
        $suffix = 'none';
        $attributes = [['foo'], ['bar']];
        $this->stateMock->expects($this->once())
            ->method('isEnabled')
            ->with(Type::TYPE_IDENTIFIER)
            ->willReturn(false);
        $this->attributeMetadataCache->save($entityType, $attributes, $suffix);
        $this->assertEquals(
            $attributes,
            $this->attributeMetadataCache->load($entityType, $suffix)
        );
    }

    public function testSave()
    {
        $entityType = 'EntityType';
        $suffix = 'none';
        $storeId = 1;
        $cacheKey = AttributeMetadataCache::ATTRIBUTE_METADATA_CACHE_PREFIX . $entityType . $suffix . $storeId;
        $serializedString = 'serialized string';
        $attributeMetadataOneData = [
            'attribute_code' => 'attribute_code',
            'frontend_input' => 'hidden',
        ];
        $this->stateMock->expects($this->once())
            ->method('isEnabled')
            ->with(Type::TYPE_IDENTIFIER)
            ->willReturn(true);

        /** @var AttributeMetadataInterface|\PHPUnit\Framework\MockObject\MockObject $attributeMetadataMock */
        $attributeMetadataMock = $this->getMockForAbstractClass(AttributeMetadataInterface::class);
        $attributesMetadata = [$attributeMetadataMock];
        $this->attributeMetadataHydratorMock->expects($this->once())
            ->method('extract')
            ->with($attributeMetadataMock)
            ->willReturn($attributeMetadataOneData);
        $this->serializerMock->expects($this->once())
            ->method('serialize')
            ->with([$attributeMetadataOneData])
            ->willReturn($serializedString);
        $this->cacheMock->expects($this->once())
            ->method('save')
            ->with(
                $serializedString,
                $cacheKey,
                [
                    Type::CACHE_TAG,
                    Attribute::CACHE_TAG,
                    System::CACHE_TAG
                ]
            );
        $this->attributeMetadataCache->save($entityType, $attributesMetadata, $suffix);
        $this->assertSame(
            $attributesMetadata,
            $this->attributeMetadataCache->load($entityType, $suffix)
        );
    }

    public function testCleanCacheDisabled()
    {
        $this->stateMock->expects($this->once())
            ->method('isEnabled')
            ->with(Type::TYPE_IDENTIFIER)
            ->willReturn(false);
        $this->cacheMock->expects($this->never())
            ->method('clean');
        $this->attributeMetadataCache->clean();
    }

    public function testClean()
    {
        $this->stateMock->expects($this->once())
            ->method('isEnabled')
            ->with(Type::TYPE_IDENTIFIER)
            ->willReturn(true);
        $this->cacheMock->expects($this->once())
            ->method('clean');
        $this->attributeMetadataCache->clean();
    }
}
