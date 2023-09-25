<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Test\Annotation;

use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Annotation\ConfigFixture;
use Magento\TestFramework\App\MutableScopeConfig;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Workaround\Override\Fixture\Resolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Util\Test as TestUtil;

/**
 * Test class for \Magento\TestFramework\Annotation\ConfigFixture.
 */
class ConfigFixtureTest extends TestCase
{
    /**
     * @var ConfigFixture|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $object;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        /** @var ObjectManagerInterface|MockObject $objectManager */
        $objectManager = $this->getMockBuilder(ObjectManagerInterface::class)
            ->onlyMethods(['get', 'create'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $objectManager->method('create')
            ->willReturnCallback(
                function (string $type) {
                    return $this->createMock($type);
                }
            );

        $objectManager->method('get')
            ->willReturnCallback(
                function (string $type) {
                    return $this->createMock($type);
                }
            );

        Bootstrap::setObjectManager($objectManager);

        $this->object = $this->createPartialMock(
            ConfigFixture::class,
            [
                '_getConfigValue',
                '_setConfigValue',
                'getScopeConfig',
                'getMutableScopeConfig',
                'setScopeConfigValue',
                'getScopeConfigValue'
            ]
        );
        $this->object->method('getMutableScopeConfig')
            ->willReturn(
                new MutableScopeConfig()
            );
    }

    /**
     * @magentoConfigFixture default/web/unsecure/base_url http://example.com/
     *
     * @return void
     */
    public function testGlobalConfig(): void
    {
        $this->createResolverMock();
        $this->object
            ->method('_getConfigValue')
            ->withConsecutive(['web/unsecure/base_url'])
            ->willReturnOnConsecutiveCalls('http://localhost/');
        $this->object
            ->method('_setConfigValue')
            ->withConsecutive(['web/unsecure/base_url', 'http://example.com/']);

        $this->object->startTest($this);

        $this->object->expects(
            $this->once()
        )->method(
            '_setConfigValue'
        )->with(
            'web/unsecure/base_url',
            'http://localhost/'
        );
        $this->object->endTest($this);
    }

    /**
     * @magentoConfigFixture base_website web/unsecure/base_url http://example.com/
     *
     * @return void
     */
    public function testSpecificWebsiteConfig(): void
    {
        $this->createResolverMock();
        $this->object
            ->method('getScopeConfigValue')
            ->withConsecutive(['web/unsecure/base_url', ScopeInterface::SCOPE_WEBSITES, 'base'])
            ->willReturnOnConsecutiveCalls('http://localhost/');
        $this->object
            ->method('setScopeConfigValue')
            ->withConsecutive(
                [
                    'web/unsecure/base_url',
                    'http://example.com/',
                    ScopeInterface::SCOPE_WEBSITES,
                    'base'
                ]
            );
        $this->object->startTest($this);

        $this->object->expects(
            $this->once()
        )->method(
            'setScopeConfigValue'
        )->with(
            'web/unsecure/base_url',
            'http://localhost/',
            ScopeInterface::SCOPE_WEBSITES,
            'base'
        );
        $this->object->endTest($this);
    }

    /**
     * @magentoConfigFixture current_website web/unsecure/base_url http://example.com/
     *
     * @return void
     */
    public function testCurrentWebsiteConfig(): void
    {
        $this->createResolverMock();
        $this->object
            ->method('getScopeConfigValue')
            ->withConsecutive(
                [
                    'web/unsecure/base_url',
                    ScopeInterface::SCOPE_WEBSITES
                ]
            )->willReturnOnConsecutiveCalls('http://localhost/');
        $this->object
            ->method('setScopeConfigValue')
            ->withConsecutive(
                [
                    'web/unsecure/base_url',
                    'http://example.com/',
                    ScopeInterface::SCOPE_WEBSITES,
                    null
                ]
            );
        $this->object->startTest($this);

        $this->object->expects(
            $this->once()
        )->method(
            'setScopeConfigValue'
        )->with(
            'web/unsecure/base_url',
            'http://localhost/',
            ScopeInterface::SCOPE_WEBSITES,
            null
        );
        $this->object->endTest($this);
    }

    /**
     * @magentoConfigFixture current_store dev/restrict/allow_ips 192.168.0.1
     *
     * @return void
     */
    public function testCurrentStoreConfig(): void
    {
        $this->createResolverMock();
        $this->object
            ->method('_getConfigValue')
            ->withConsecutive(['dev/restrict/allow_ips', ''])
            ->willReturnOnConsecutiveCalls('127.0.0.1');
        $this->object
            ->method('_setConfigValue')
            ->withConsecutive(['dev/restrict/allow_ips', '192.168.0.1', '']);
        $this->object->startTest($this);

        $this->object->expects(
            $this->once()
        )->method(
            'setScopeConfigValue'
        )->with(
            'dev/restrict/allow_ips',
            '127.0.0.1',
            ScopeInterface::SCOPE_STORES,
            ''
        );
        $this->object->endTest($this);
    }

    /**
     * @magentoConfigFixture admin_store dev/restrict/allow_ips 192.168.0.2
     *
     * @return void
     */
    public function testSpecificStoreConfig(): void
    {
        $this->createResolverMock();
        $this->object
            ->method('_getConfigValue')
            ->withConsecutive(['dev/restrict/allow_ips', 'admin'])
            ->willReturnOnConsecutiveCalls('192.168.0.1');
        $this->object
            ->method('_setConfigValue')
            ->withConsecutive(['dev/restrict/allow_ips', '192.168.0.2', 'admin']);
        $this->object->startTest($this);

        $this->object->expects(
            $this->once()
        )->method(
            'setScopeConfigValue'
        )->with(
            'dev/restrict/allow_ips',
            '192.168.0.1',
            ScopeInterface::SCOPE_STORES,
            'admin'
        );
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
     * @magentoConfigFixture current_store web/unsecure/base_url http://example.com/
     *
     * @return void
     */
    public function testInitStoreAfter(): void
    {
        $this->createResolverMock();
        $this->object->startTest($this);
        $this->object
            ->method('_getConfigValue')
            ->withConsecutive(['web/unsecure/base_url'])
            ->willReturnOnConsecutiveCalls('http://localhost/');
        $this->object
            ->method('_setConfigValue')
            ->withConsecutive(['web/unsecure/base_url', 'http://example.com/']);
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
