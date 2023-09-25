<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\App\Test\Unit;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\App\State;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class BootstrapTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\AppInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $application;

    /**
     * @var \Magento\Framework\App\ObjectManagerFactory | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectManagerFactory;

    /**
     * @var \Magento\Framework\ObjectManager\ObjectManager | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectManager;

    /**
     * @var \Psr\Log\LoggerInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $logger;

    /**
     * @var \Magento\Framework\App\Filesystem\DirectoryList | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $dirs;

    /**
     * @var \Magento\Framework\Filesystem\Directory\ReadInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $configDir;

    /**
     * @var MaintenanceMode | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $maintenanceMode;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $deploymentConfig;

    /**
     * @var \Magento\Framework\App\Bootstrap | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $bootstrapMock;

    /**
     * @var \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $remoteAddress;

    protected function setUp(): void
    {
        $this->objectManagerFactory = $this->createMock(\Magento\Framework\App\ObjectManagerFactory::class);
        $this->objectManager = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $this->dirs = $this->createPartialMock(\Magento\Framework\App\Filesystem\DirectoryList::class, ['getPath']);
        $this->maintenanceMode = $this->createPartialMock(\Magento\Framework\App\MaintenanceMode::class, ['isOn']);
        $this->remoteAddress = $this->createMock(\Magento\Framework\HTTP\PhpEnvironment\RemoteAddress::class);
        $filesystem = $this->createMock(\Magento\Framework\Filesystem::class);

        $this->logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $this->deploymentConfig = $this->createMock(\Magento\Framework\App\DeploymentConfig::class);

        $mapObjectManager = [
            [\Magento\Framework\App\Filesystem\DirectoryList::class, $this->dirs],
            [\Magento\Framework\App\MaintenanceMode::class, $this->maintenanceMode],
            [\Magento\Framework\HTTP\PhpEnvironment\RemoteAddress::class, $this->remoteAddress],
            [\Magento\Framework\Filesystem::class, $filesystem],
            [\Magento\Framework\App\DeploymentConfig::class, $this->deploymentConfig],
            [\Psr\Log\LoggerInterface::class, $this->logger],
        ];

        $this->objectManager->expects($this->any())->method('get')
            ->will(($this->returnValueMap($mapObjectManager)));

        $this->configDir = $this->getMockForAbstractClass(\Magento\Framework\Filesystem\Directory\ReadInterface::class);

        $filesystem->expects($this->any())->method('getDirectoryRead')
            ->will(($this->returnValue($this->configDir)));

        $this->application = $this->getMockForAbstractClass(\Magento\Framework\AppInterface::class);

        $this->objectManager->expects($this->any())->method('create')
            ->will(($this->returnValue($this->application)));

        $this->objectManagerFactory->expects($this->any())->method('create')
            ->will(($this->returnValue($this->objectManager)));

        $this->bootstrapMock = $this->getMockBuilder(\Magento\Framework\App\Bootstrap::class)
            ->setMethods(['assertMaintenance', 'assertInstalled', 'getIsExpected', 'isInstalled', 'terminate'])
            ->setConstructorArgs([$this->objectManagerFactory, '', ['value1', 'value2']])
            ->getMock();
    }

    public function testCreateObjectManagerFactory()
    {
        $result = Bootstrap::createObjectManagerFactory('test', []);
        $this->assertInstanceOf(\Magento\Framework\App\ObjectManagerFactory::class, $result);
    }

    public function testCreateFilesystemDirectoryList()
    {
        $result = Bootstrap::createFilesystemDirectoryList(
            'test',
            [Bootstrap::INIT_PARAM_FILESYSTEM_DIR_PATHS => [DirectoryList::APP => ['path' => '/custom/path']]]
        );
        /** @var \Magento\Framework\App\Filesystem\DirectoryList $result */
        $this->assertInstanceOf(\Magento\Framework\App\Filesystem\DirectoryList::class, $result);
        $this->assertEquals('/custom/path', $result->getPath(DirectoryList::APP));
    }

    public function testCreateFilesystemDriverPool()
    {
        $driverClass = get_class($this->getMockForAbstractClass(\Magento\Framework\Filesystem\DriverInterface::class));
        $result = Bootstrap::createFilesystemDriverPool(
            [Bootstrap::INIT_PARAM_FILESYSTEM_DRIVERS => ['custom' => $driverClass]]
        );
        /** @var \Magento\Framework\Filesystem\DriverPool $result */
        $this->assertInstanceOf(\Magento\Framework\Filesystem\DriverPool::class, $result);
        $this->assertInstanceOf($driverClass, $result->getDriver('custom'));
    }

    public function testGetParams()
    {
        $testParams = ['testValue1', 'testValue2'];
        $bootstrap = self::createBootstrap($testParams);
        $this->assertSame($testParams, $bootstrap->getParams());
    }

    /**
     * Creates a bootstrap object
     *
     * @param array $testParams
     * @return Bootstrap
     */
    private function createBootstrap($testParams = ['value1', 'value2'])
    {
        return new Bootstrap($this->objectManagerFactory, '', $testParams);
    }

    public function testCreateApplication()
    {
        $bootstrap = self::createBootstrap();
        $testArgs = ['arg1', 'arg2'];
        $this->assertSame($this->application, $bootstrap->createApplication('someApplicationType', $testArgs));
    }

    public function testGetObjectManager()
    {
        $bootstrap = self::createBootstrap();
        $this->assertSame($this->objectManager, $bootstrap->getObjectManager());
    }

    /**
     * @param $modeFromEnvironment
     * @param $modeFromDeployment
     * @param $isDeveloper
     *
     * @dataProvider testIsDeveloperModeDataProvider
     */
    public function testIsDeveloperMode($modeFromEnvironment, $modeFromDeployment, $isDeveloper)
    {
        $testParams = [];
        if ($modeFromEnvironment) {
            $testParams[State::PARAM_MODE] = $modeFromEnvironment;
        }
        if ($modeFromDeployment) {
            $this->deploymentConfig->method('get')->willReturn($modeFromDeployment);
        }
        $bootstrap = self::createBootstrap($testParams);
        $this->assertEquals($isDeveloper, $bootstrap->isDeveloperMode());
    }

    /**
     * @return array
     */
    public function testIsDeveloperModeDataProvider()
    {
        return [
            [null, null, false],
            [State::MODE_DEVELOPER, State::MODE_PRODUCTION, true],
            [State::MODE_PRODUCTION, State::MODE_DEVELOPER, false],
            [null, State::MODE_DEVELOPER, true],
            [null, State::MODE_PRODUCTION, false]
        ];
    }

    public function testRunNoErrors()
    {
        $responseMock = $this->getMockForAbstractClass(\Magento\Framework\App\ResponseInterface::class);
        $this->bootstrapMock->expects($this->once())->method('assertMaintenance')->willReturn(null);
        $this->bootstrapMock->expects($this->once())->method('assertInstalled')->willReturn(null);
        $this->application->expects($this->once())->method('launch')->willReturn($responseMock);
        $this->bootstrapMock->run($this->application);
    }

    public function testRunWithMaintenanceErrors()
    {
        $expectedException = new \Exception('');
        $this->bootstrapMock->expects($this->once())->method('assertMaintenance')
            ->will($this->throwException($expectedException));
        $this->bootstrapMock->expects($this->once())->method('terminate')->with($expectedException);
        $this->application->expects($this->once())->method('catchException')->willReturn(false);
        $this->bootstrapMock->run($this->application);
    }

    public function testRunWithInstallErrors()
    {
        $expectedException = new \Exception('');
        $this->bootstrapMock->expects($this->once())->method('assertMaintenance')->willReturn(null);
        $this->bootstrapMock->expects($this->once())->method('assertInstalled')
            ->will($this->throwException($expectedException));
        $this->bootstrapMock->expects($this->once())->method('terminate')->with($expectedException);
        $this->application->expects($this->once())->method('catchException')->willReturn(false);
        $this->bootstrapMock->run($this->application);
    }

    public function testRunWithBothErrors()
    {
        $expectedMaintenanceException = new \Exception('');
        $this->bootstrapMock->expects($this->once())->method('assertMaintenance')
            ->will($this->throwException($expectedMaintenanceException));
        $this->bootstrapMock->expects($this->never())->method('assertInstalled');
        $this->bootstrapMock->expects($this->once())->method('terminate')->with($expectedMaintenanceException);
        $this->application->expects($this->once())->method('catchException')->willReturn(false);
        $this->bootstrapMock->run($this->application);
    }

    /**
     * @param bool $isOn
     * @param bool $isExpected
     *
     * @dataProvider assertMaintenanceDataProvider
     */
    public function testAssertMaintenance($isOn, $isExpected)
    {
        $bootstrap = self::createBootstrap([Bootstrap::PARAM_REQUIRE_MAINTENANCE => $isExpected]);
        $this->maintenanceMode->expects($this->once())->method('isOn')->willReturn($isOn);
        $this->remoteAddress->expects($this->once())->method('getRemoteAddress')->willReturn(false);
        $this->application->expects($this->never())->method('launch');
        $this->application->expects($this->once())->method('catchException')->willReturn(true);
        $bootstrap->run($this->application);
        $this->assertEquals(Bootstrap::ERR_MAINTENANCE, $bootstrap->getErrorCode());
    }

    /**
     * @return array
     */
    public function assertMaintenanceDataProvider()
    {
        return [
            [true, false],
            [false, true]
        ];
    }

    /**
     * @param bool $isInstalled
     * @param bool $isExpected
     *
     * @dataProvider assertInstalledDataProvider
     */
    public function testAssertInstalled($isInstalled, $isExpected)
    {
        $bootstrap = self::createBootstrap([Bootstrap::PARAM_REQUIRE_IS_INSTALLED => $isExpected]);
        $this->deploymentConfig->expects($this->once())->method('isAvailable')->willReturn($isInstalled);
        $this->application->expects($this->never())->method('launch');
        $this->application->expects($this->once())->method('catchException')->willReturn(true);
        $bootstrap->run($this->application);
        $this->assertEquals(Bootstrap::ERR_IS_INSTALLED, $bootstrap->getErrorCode());
    }

    /**
     * @return array
     */
    public function assertInstalledDataProvider()
    {
        return [
            [false, true],
            [true, false],
        ];
    }

    /**
     * Restore error handler after Bootstrap->run method
     */
    protected function tearDown(): void
    {
        restore_error_handler();
        setCustomErrorHandler();
    }
}
