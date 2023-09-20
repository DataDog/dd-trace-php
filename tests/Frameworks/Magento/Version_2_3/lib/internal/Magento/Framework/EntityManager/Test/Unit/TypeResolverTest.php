<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\EntityManager\Test\Unit;

class TypeResolverTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    private $objectManager;

    /**
     * @var \Magento\Framework\EntityManager\TypeResolver
     */
    private $resolver;

    /**
     * @var \Magento\Framework\EntityManager\MetadataPool|\PHPUnit\Framework\MockObject\MockObject
     */
    private $metadataPoolMock;

    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->metadataPoolMock =
            $this->createMock(\Magento\Framework\EntityManager\MetadataPool::class);
        $this->resolver = new \Magento\Framework\EntityManager\TypeResolver($this->metadataPoolMock);
    }

    /**
     * @param object $dataObject
     * @param string $interfaceName
     * @dataProvider resolveDataProvider
     */
    public function testResolve($dataObject, $interfaceName)
    {
        $customerDataObject = $this->objectManager->getObject($dataObject);
        $this->metadataPoolMock->expects($this->any())
            ->method('hasConfiguration')
            ->willReturnMap(
                [
                   [$interfaceName, true]
                ]
            );
        $this->assertEquals($interfaceName, $this->resolver->resolve($customerDataObject));
    }

    /**
     * @return array
     */
    public function resolveDataProvider()
    {
        return [
            [
                \Magento\Customer\Model\Data\Customer::class,
                \Magento\Customer\Api\Data\CustomerInterface::class
            ],
            [
                \Magento\Catalog\Model\Category::class,
                \Magento\Catalog\Api\Data\CategoryInterface::class,
            ]
        ];
    }
}
