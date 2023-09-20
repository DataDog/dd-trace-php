<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Setup\Test\Unit\Model;

use Magento\Framework\Config\File\ConfigFilePool;
use Magento\Setup\Model\ConfigModel;

class ConfigModelTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Setup\Model\ConfigModel
     */
    private $configModel;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Setup\Model\ConfigOptionsListCollector
     */
    private $collector;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\App\DeploymentConfig\Writer
     */
    private $writer;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\App\DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject |\Magento\Framework\Config\Data\ConfigData
     */
    private $configData;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\Setup\FilePermissions
     */
    private $filePermissions;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Backend\Setup\ConfigOptionsList
     */
    private $configOptionsList;

    protected function setUp(): void
    {
        $this->collector = $this->createMock(\Magento\Setup\Model\ConfigOptionsListCollector::class);
        $this->writer = $this->createMock(\Magento\Framework\App\DeploymentConfig\Writer::class);
        $this->deploymentConfig = $this->createMock(\Magento\Framework\App\DeploymentConfig::class);
        $this->configOptionsList = $this->createMock(\Magento\Backend\Setup\ConfigOptionsList::class);
        $this->configData = $this->createMock(\Magento\Framework\Config\Data\ConfigData::class);
        $this->filePermissions = $this->createMock(\Magento\Framework\Setup\FilePermissions::class);

        $this->deploymentConfig->expects($this->any())->method('get');

        $this->configModel = new ConfigModel(
            $this->collector,
            $this->writer,
            $this->deploymentConfig,
            $this->filePermissions
        );
    }

    public function testValidate()
    {
        $option = $this->createMock(\Magento\Framework\Setup\Option\TextConfigOption::class);
        $option->expects($this->exactly(3))->method('getName')->willReturn('Fake');
        $optionsSet = [
            $option,
            $option,
            $option
        ];
        $configOption = $this->configOptionsList;
        $configOption->expects($this->once())->method('getOptions')->willReturn($optionsSet);
        $configOption->expects($this->once())->method('validate')->willReturn([]);

        $this->collector
            ->expects($this->exactly(2))
            ->method('collectOptionsLists')
            ->willReturn([$configOption]);

        $this->configModel->validate(['Fake' => null]);
    }

    public function testProcess()
    {
        $testSet1 = [
            ConfigFilePool::APP_CONFIG => [
                'segment' => [
                    'someKey' => 'value',
                    'test' => 'value1'
                ]
            ]
        ];

        $testSet2 = [
            ConfigFilePool::APP_CONFIG => [
                'segment' => [
                    'test' => 'value2'
                ]
            ]
        ];

        $testSetExpected1 = [
            ConfigFilePool::APP_CONFIG => [
                'segment' => [
                    'someKey' => 'value',
                    'test' => 'value1'
                ]
            ]
        ];

        $testSetExpected2 = [
            ConfigFilePool::APP_CONFIG => [
                'segment' => [
                    'test' => 'value2'
                ]
            ]
        ];

        $configData1 = clone $this->configData;
        $configData2 = clone $this->configData;

        $configData1->expects($this->any())
            ->method('getData')
            ->willReturn($testSet1[ConfigFilePool::APP_CONFIG]);
        $configData1->expects($this->any())->method('getFileKey')->willReturn(ConfigFilePool::APP_CONFIG);
        $configData1->expects($this->once())->method('isOverrideWhenSave')->willReturn(false);

        $configData2->expects($this->any())
            ->method('getData')
            ->willReturn($testSet2[ConfigFilePool::APP_CONFIG]);
        $configData2->expects($this->any())->method('getFileKey')->willReturn(ConfigFilePool::APP_CONFIG);
        $configData2->expects($this->once())->method('isOverrideWhenSave')->willReturn(false);

        $configOption = $this->configOptionsList;
        $configOption->expects($this->once())
            ->method('createConfig')
            ->willReturn([$configData1, $configData2]);

        $configOptionsList = [
            'Fake_Module' => $configOption
        ];
        $this->collector->expects($this->once())
            ->method('collectOptionsLists')
            ->willReturn($configOptionsList);

        $this->writer->expects($this->at(0))->method('saveConfig')->with($testSetExpected1);
        $this->writer->expects($this->at(1))->method('saveConfig')->with($testSetExpected2);

        $this->configModel->process([]);
    }

    /**
     */
    public function testProcessException()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('In module : Fake_ModuleConfigOption::createConfig');

        $configOption = $this->configOptionsList;
        $configOption->expects($this->once())
            ->method('createConfig')
            ->willReturn([null]);

        $wrongData = [
            'Fake_Module' => $configOption
        ];

        $this->collector->expects($this->once())->method('collectOptionsLists')->willReturn($wrongData);

        $this->configModel->process([]);
    }

    /**
     */
    public function testWritePermissionErrors()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing write permissions to the following paths:');

        $this->filePermissions->expects($this->once())->method('getMissingWritablePathsForInstallation')
            ->willReturn(['/a/ro/dir', '/media']);
        $this->configModel->process([]);
    }
}
