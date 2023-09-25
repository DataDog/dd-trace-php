<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Setup\Test\Unit\Fixtures;

use \Magento\Setup\Fixtures\IndexersStatesApplyFixture;

class IndexersStatesApplyFixtureTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Setup\Fixtures\FixtureModel
     */
    private $fixtureModelMock;

    /**
     * @var \Magento\Setup\Fixtures\IndexersStatesApplyFixture
     */
    private $model;

    protected function setUp(): void
    {
        $this->fixtureModelMock = $this->createMock(\Magento\Setup\Fixtures\FixtureModel::class);

        $this->model = new IndexersStatesApplyFixture($this->fixtureModelMock);
    }

    public function testExecute()
    {
        $cacheInterfaceMock = $this->createMock(\Magento\Framework\App\CacheInterface::class);
        $indexerRegistryMock = $this->createMock(\Magento\Framework\Indexer\IndexerRegistry::class);
        $indexerMock = $this->getMockForAbstractClass(\Magento\Framework\Indexer\IndexerInterface::class);

        $indexerRegistryMock->expects($this->once())
            ->method('get')
            ->willReturn($indexerMock);

        $indexerMock->expects($this->once())
            ->method('setScheduled');

        $objectManagerMock = $this->createMock(\Magento\Framework\ObjectManager\ObjectManager::class);
        $objectManagerMock->expects($this->once())
            ->method('get')
            ->willReturn($cacheInterfaceMock);
        $objectManagerMock->expects($this->once())
            ->method('create')
            ->willReturn($indexerRegistryMock);

        $this->fixtureModelMock
            ->expects($this->once())
            ->method('getValue')
            ->willReturn([
                'indexer' => [
                    [
                        'id' => 1,
                        'set_scheduled' => false,
                    ]
                ]
            ]);
        $this->fixtureModelMock
            ->method('getObjectManager')
            ->willReturn($objectManagerMock);

        $this->model->execute();
    }

    public function testNoFixtureConfigValue()
    {
        $cacheInterfaceMock = $this->createMock(\Magento\Framework\App\CacheInterface::class);
        $cacheInterfaceMock->expects($this->never())->method('clean');

        $objectManagerMock = $this->createMock(\Magento\Framework\ObjectManager\ObjectManager::class);
        $objectManagerMock->expects($this->never())
            ->method('get')
            ->willReturn($cacheInterfaceMock);

        $this->fixtureModelMock
            ->expects($this->never())
            ->method('getObjectManager')
            ->willReturn($objectManagerMock);
        $this->fixtureModelMock
            ->expects($this->once())
            ->method('getValue')
            ->willReturn(false);

        $this->model->execute();
    }

    public function testGetActionTitle()
    {
        $this->assertSame('Indexers Mode Changes', $this->model->getActionTitle());
    }

    public function testIntroduceParamLabels()
    {
        $this->assertSame([], $this->model->introduceParamLabels());
    }
}
