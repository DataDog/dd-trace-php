<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Developer\Test\Unit\Model\Logger\Handler;

use Magento\Developer\Model\Logger\Handler\Debug;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Store\Model\ScopeInterface;
use Monolog\Formatter\FormatterInterface;
use Monolog\Logger;
use Magento\Framework\App\DeploymentConfig;

/**
 * Class DebugTest
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DebugTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Debug
     */
    private $model;

    /**
     * @var DriverInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $filesystemMock;

    /**
     * @var State|\PHPUnit\Framework\MockObject\MockObject
     */
    private $stateMock;

    /**
     * @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $scopeConfigMock;

    /**
     * @var FormatterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $formatterMock;

    /**
     * @var DeploymentConfig|\PHPUnit\Framework\MockObject\MockObject
     */
    private $deploymentConfigMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->filesystemMock = $this->getMockBuilder(DriverInterface::class)
            ->getMockForAbstractClass();
        $this->stateMock = $this->getMockBuilder(State::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->scopeConfigMock = $this->getMockBuilder(ScopeConfigInterface::class)
            ->getMockForAbstractClass();
        $this->formatterMock = $this->getMockBuilder(FormatterInterface::class)
            ->getMockForAbstractClass();
        $this->deploymentConfigMock = $this->getMockBuilder(DeploymentConfig::class)
            ->disableOriginalConstructor()
            ->disableOriginalClone()
            ->getMock();

        $this->formatterMock->expects($this->any())
            ->method('format')
            ->willReturn(null);

        $this->model = (new ObjectManager($this))->getObject(Debug::class, [
            'filesystem' => $this->filesystemMock,
            'state' => $this->stateMock,
            'scopeConfig' => $this->scopeConfigMock,
            'deploymentConfig' => $this->deploymentConfigMock
        ]);
        $this->model->setFormatter($this->formatterMock);
    }

    /**
     * @return void
     */
    public function testHandleEnabledInDeveloperMode()
    {
        $this->deploymentConfigMock->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);
        $this->stateMock
            ->expects($this->once())
            ->method('getMode')
            ->willReturn(State::MODE_DEVELOPER);
        $this->scopeConfigMock
            ->expects($this->never())
            ->method('getValue');

        $this->assertTrue($this->model->isHandling(['formatted' => false, 'level' => Logger::DEBUG]));
    }

    /**
     * @return void
     */
    public function testHandleEnabledInDefaultMode()
    {
        $this->deploymentConfigMock->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);
        $this->stateMock
            ->expects($this->once())
            ->method('getMode')
            ->willReturn(State::MODE_DEFAULT);
        $this->scopeConfigMock
            ->expects($this->never())
            ->method('getValue');

        $this->assertTrue($this->model->isHandling(['formatted' => false, 'level' => Logger::DEBUG]));
    }

    /**
     * @return void
     */
    public function testHandleDisabledByProduction()
    {
        $this->deploymentConfigMock->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);
        $this->stateMock
            ->expects($this->once())
            ->method('getMode')
            ->willReturn(State::MODE_PRODUCTION);
        $this->scopeConfigMock
            ->expects($this->never())
            ->method('getValue');

        $this->assertFalse($this->model->isHandling(['formatted' => false, 'level' => Logger::DEBUG]));
    }

    /**
     * @return void
     */
    public function testHandleDisabledByLevel()
    {
        $this->deploymentConfigMock->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);
        $this->stateMock
            ->expects($this->never())
            ->method('getMode')
            ->willReturn(State::MODE_DEVELOPER);
        $this->scopeConfigMock
            ->expects($this->never())
            ->method('getValue');

        $this->assertFalse($this->model->isHandling(['formatted' => false, 'level' => Logger::API]));
    }

    /**
     * @return void
     */
    public function testDeploymentConfigIsNotAvailable()
    {
        $this->deploymentConfigMock->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false);
        $this->stateMock
            ->expects($this->never())
            ->method('getMode');
        $this->scopeConfigMock
            ->expects($this->never())
            ->method('getValue');

        $this->assertTrue($this->model->isHandling(['formatted' => false, 'level' => Logger::DEBUG]));
    }
}
