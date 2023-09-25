<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Theme\Test\Unit\Model;

use Magento\Theme\Model\Data\Design\Config;
use Magento\Theme\Model\DesignConfigRepository;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class DesignConfigRepositoryTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Theme\Model\Design\Config\Storage|\PHPUnit\Framework\MockObject\MockObject */
    protected $configStorage;

    /** @var \Magento\Framework\App\Config\ReinitableConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $reinitableConfig;

    /** @var \Magento\Framework\Indexer\IndexerRegistry|\PHPUnit\Framework\MockObject\MockObject */
    protected $indexerRegistry;

    /** @var \Magento\Theme\Api\Data\DesignConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $designConfig;

    /** @var \Magento\Theme\Api\Data\DesignConfigExtensionInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $designExtension;

    /** @var \Magento\Theme\Api\Data\DesignConfigDataInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $designConfigData;

    /** @var \Magento\Framework\Indexer\IndexerInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $indexer;

    /** @var DesignConfigRepository */
    protected $repository;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $validator;

    protected function setUp(): void
    {
        $this->configStorage = $this->createMock(\Magento\Theme\Model\Design\Config\Storage::class);
        $this->reinitableConfig = $this->getMockForAbstractClass(
            \Magento\Framework\App\Config\ReinitableConfigInterface::class,
            [],
            '',
            false
        );
        $this->indexerRegistry = $this->createMock(\Magento\Framework\Indexer\IndexerRegistry::class);
        $this->designConfig = $this->getMockForAbstractClass(
            \Magento\Theme\Api\Data\DesignConfigInterface::class,
            [],
            '',
            false
        );
        $this->designExtension = $this->getMockForAbstractClass(
            \Magento\Theme\Api\Data\DesignConfigExtensionInterface::class,
            [],
            '',
            false,
            false,
            true,
            ['getDesignConfigData']
        );
        $this->designConfigData = $this->getMockForAbstractClass(
            \Magento\Theme\Api\Data\DesignConfigDataInterface::class,
            [],
            '',
            false
        );
        $this->indexer = $this->getMockForAbstractClass(
            \Magento\Framework\Indexer\IndexerInterface::class,
            [],
            '',
            false
        );

        $this->validator = $this->createMock(\Magento\Theme\Model\Design\Config\Validator::class);
        $objectManagerHelper = new ObjectManager($this);
        $this->repository = $objectManagerHelper->getObject(
            DesignConfigRepository::class,
            [
                'configStorage' => $this->configStorage,
                'reinitableConfig' => $this->reinitableConfig,
                'indexerRegistry' => $this->indexerRegistry,
                'validator' => $this->validator
            ]
        );
    }

    public function testSave()
    {
        $this->designConfig->expects($this->exactly(2))
            ->method('getExtensionAttributes')
            ->willReturn($this->designExtension);
        $this->designExtension->expects($this->once())
            ->method('getDesignConfigData')
            ->willReturn([$this->designConfigData]);
        $this->configStorage->expects($this->once())
            ->method('save')
            ->willReturn($this->designConfig);
        $this->reinitableConfig->expects($this->once())
            ->method('reinit');
        $this->indexerRegistry->expects($this->once())
            ->method('get')
            ->with(Config::DESIGN_CONFIG_GRID_INDEXER_ID)
            ->willReturn($this->indexer);
        $this->indexer->expects($this->once())
            ->method('reindexAll');
        $this->validator->expects($this->once())->method('validate')->with($this->designConfig);
        $this->assertSame($this->designConfig, $this->repository->save($this->designConfig));
    }

    /**
     */
    public function testSaveWithoutConfig()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('The config can\'t be saved because it\'s empty. Complete the config and try again.');

        $this->designConfig->expects($this->exactly(2))
            ->method('getExtensionAttributes')
            ->willReturn($this->designExtension);
        $this->designExtension->expects($this->once())
            ->method('getDesignConfigData')
            ->willReturn(false);
        $this->repository->save($this->designConfig);
    }

    public function testDelete()
    {
        $this->designConfig->expects($this->exactly(2))
            ->method('getExtensionAttributes')
            ->willReturn($this->designExtension);
        $this->designExtension->expects($this->once())
            ->method('getDesignConfigData')
            ->willReturn([$this->designConfigData]);
        $this->configStorage->expects($this->once())
            ->method('delete')
            ->with($this->designConfig);
        $this->reinitableConfig->expects($this->once())
            ->method('reinit');
        $this->indexerRegistry->expects($this->once())
            ->method('get')
            ->with(Config::DESIGN_CONFIG_GRID_INDEXER_ID)
            ->willReturn($this->indexer);
        $this->indexer->expects($this->once())
            ->method('reindexAll');
        $this->assertSame($this->designConfig, $this->repository->delete($this->designConfig));
    }
}
