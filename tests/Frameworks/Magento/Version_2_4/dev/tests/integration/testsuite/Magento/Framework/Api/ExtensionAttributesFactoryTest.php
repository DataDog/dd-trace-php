<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Api;

class ExtensionAttributesFactoryTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Framework\Api\ExtensionAttributesFactory */
    private $factory;

    protected function setUp(): void
    {
        /** @var \Magento\Framework\ObjectManagerInterface */
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();

        $this->factory = $objectManager->create(
            \Magento\Framework\Api\ExtensionAttributesFactory::class,
            ['objectManager' => $objectManager]
        );
    }

    /**
     */
    public function testCreateThrowExceptionIfInterfaceNotImplemented()
    {
        $this->expectException(\LogicException::class);

        $this->factory->create(\Magento\Framework\Api\ExtensionAttributesFactoryTest::class);
    }

    /**
     */
    public function testCreateThrowExceptionIfInterfaceNotOverridden()
    {
        $this->expectException(\LogicException::class);

        $this->factory->create(\Magento\TestModuleExtensionAttributes\Model\Data\FakeExtensibleOne::class);
    }

    /**
     */
    public function testCreateThrowExceptionIfReturnIsIncorrect()
    {
        $this->expectException(\LogicException::class);

        $this->factory->create(\Magento\TestModuleExtensionAttributes\Model\Data\FakeExtensibleTwo::class);
    }

    public function testCreate()
    {
        $this->assertInstanceOf(
            \Magento\TestModuleExtensionAttributes\Api\Data\FakeRegionExtension::class,
            $this->factory->create(\Magento\TestModuleExtensionAttributes\Model\Data\FakeRegion::class)
        );
    }

    public function testCreateWithLogicException()
    {
        $this->expectException('LogicException');
        $this->expectExceptionMessage(
            "Class 'Magento\\Framework\\Api\\ExtensionAttributesFactoryTest' must implement an interface, "
            . "which extends from 'Magento\\Framework\\Api\\ExtensibleDataInterface'"
        );
        $this->factory->create(get_class($this));
    }
}
