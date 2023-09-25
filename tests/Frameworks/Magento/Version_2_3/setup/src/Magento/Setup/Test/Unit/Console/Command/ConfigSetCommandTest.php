<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Setup\Test\Unit\Console\Command;

use Magento\Framework\Module\ModuleList;
use Magento\Setup\Console\Command\ConfigSetCommand;
use Symfony\Component\Console\Tester\CommandTester;

class ConfigSetCommandTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Setup\Model\ConfigModel
     */
    private $configModel;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\App\DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Setup\Console\Command\ConfigSetCommand
     */
    private $command;

    protected function setUp(): void
    {
        $option = $this->createMock(\Magento\Framework\Setup\Option\TextConfigOption::class);
        $option
            ->expects($this->any())
            ->method('getName')
            ->willReturn('db-host');
        $this->configModel = $this->createMock(\Magento\Setup\Model\ConfigModel::class);
        $this->configModel
            ->expects($this->exactly(2))
            ->method('getAvailableOptions')
            ->willReturn([$option]);
        $moduleList = $this->createMock(\Magento\Framework\Module\ModuleList::class);
        $this->deploymentConfig = $this->createMock(\Magento\Framework\App\DeploymentConfig::class);
        $this->command = new ConfigSetCommand($this->configModel, $moduleList, $this->deploymentConfig);
    }

    public function testExecuteNoInteractive()
    {
        $this->deploymentConfig
            ->expects($this->once())
            ->method('get')
            ->willReturn(null);
        $this->configModel
            ->expects($this->once())
            ->method('process')
            ->with(['db-host' => 'host']);
        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['--db-host' => 'host']);
        $this->assertSame(
            'You saved the new configuration.' . PHP_EOL,
            $commandTester->getDisplay()
        );
    }

    public function testExecuteInteractiveWithYes()
    {
        $this->deploymentConfig
            ->expects($this->once())
            ->method('get')
            ->willReturn('localhost');
        $this->configModel
            ->expects($this->once())
            ->method('process')
            ->with(['db-host' => 'host']);
        $this->checkInteraction('Y');
    }

    public function testExecuteInteractiveWithNo()
    {
        $this->deploymentConfig
            ->expects($this->once())
            ->method('get')
            ->willReturn('localhost');
        $this->configModel
            ->expects($this->once())
            ->method('process')
            ->with([]);
        $this->checkInteraction('n');
    }

    /**
     * Checks interaction with users on CLI
     *
     * @param string $interactionType
     * @return void
     */
    private function checkInteraction($interactionType)
    {
        $dialog = $this->createMock(\Symfony\Component\Console\Helper\QuestionHelper::class);
        $dialog
            ->expects($this->once())
            ->method('ask')
            ->willReturn($interactionType);

        /** @var \Symfony\Component\Console\Helper\HelperSet|\PHPUnit\Framework\MockObject\MockObject $helperSet */
        $helperSet = $this->createMock(\Symfony\Component\Console\Helper\HelperSet::class);
        $helperSet
            ->expects($this->once())
            ->method('get')
            ->with('question')
            ->willReturn($dialog);
        $this->command->setHelperSet($helperSet);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['--db-host' => 'host']);
        if (strtolower($interactionType) === 'y') {
            $message = 'You saved the new configuration.' . PHP_EOL;
        } else {
            $message = 'You made no changes to the configuration.'.PHP_EOL;
        }
        $this->assertSame(
            $message,
            $commandTester->getDisplay()
        );
    }
}
