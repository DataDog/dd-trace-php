<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Test\Annotation;

use Magento\TestFramework\Annotation\AdminConfigFixture;
use Magento\TestFramework\Workaround\Override\Fixture\Resolver;
use PHPUnit\Framework\TestCase;
use PHPUnit\Util\Test as TestUtil;

/**
 * Test class for \Magento\TestFramework\Annotation\AdminConfigFixture.
 */
class AdminConfigFixtureTest extends TestCase
{
    /**
     * @var AdminConfigFixture|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $object;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->object = $this->createPartialMock(
            AdminConfigFixture::class,
            ['_getConfigValue', '_setConfigValue']
        );
    }

    /**
     * @magentoAdminConfigFixture any_config some_value
     *
     * @return void
     */
    public function testConfig(): void
    {
        $this->createResolverMock();
        $this->object
            ->method('_getConfigValue')
            ->withConsecutive(['any_config'], ['any_config'])
            ->willReturnOnConsecutiveCalls('some_value', 'some_value');

        $this->object->startTest($this);

        $this->object
            ->expects($this->once())
            ->method('_setConfigValue')
            ->with('any_config', 'some_value');

        $this->object->endTest($this);
    }

    /**
     * @return void
     */
    public function testInitStoreAfterOfScope(): void
    {
        $this->object->expects($this->never())->method('_getConfigValue');
        $this->object->expects($this->never())->method('_setConfigValue');
        $this->object->initStoreAfter();
    }

    /**
     * @magentoAdminConfigFixture any_config some_value
     *
     * @return void
     */
    public function testInitStoreAfter(): void
    {
        $this->createResolverMock();
        $this->object->startTest($this);
        $this->object
            ->method('_getConfigValue')
            ->withConsecutive(['any_config'], ['any_config'])
            ->willReturnOnConsecutiveCalls('some_value', 'some_value');

        $this->object->initStoreAfter();
    }

    /**
     * Create mock for Resolver object
     *
     * @return void
     */
    private function createResolverMock(): void
    {
        $mock = $this->getMockBuilder(Resolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['applyConfigFixtures'])
            ->getMock();
        $annotations = TestUtil::parseTestMethodAnnotations(
            get_class($this),
            $this->getName(false)
        );
        $mock->method('applyConfigFixtures')
            ->willReturn($annotations['method'][$this->object::ANNOTATION]);
        $reflection = new \ReflectionClass(Resolver::class);
        $reflectionProperty = $reflection->getProperty('instance');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(Resolver::class, $mock);
    }
}
