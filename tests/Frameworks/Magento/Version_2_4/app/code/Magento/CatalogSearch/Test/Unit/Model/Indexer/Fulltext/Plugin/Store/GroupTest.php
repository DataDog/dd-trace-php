<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CatalogSearch\Test\Unit\Model\Indexer\Fulltext\Plugin\Store;

use Magento\CatalogSearch\Model\Indexer\Fulltext as FulltextIndexer;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Plugin\Store\Group as StoreGroupIndexerPlugin;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Store\Model\Group as StoreGroup;
use Magento\Store\Model\ResourceModel\Group as StoreGroupResourceModel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GroupTest extends TestCase
{
    /**
     * @var StoreGroupIndexerPlugin
     */
    private $plugin;

    /**
     * @var IndexerRegistry|MockObject
     */
    private $indexerRegistryMock;

    /**
     * @var IndexerInterface|MockObject
     */
    private $indexerMock;

    /**
     * @var StoreGroupResourceModel|MockObject
     */
    private $subjectMock;

    /**
     * @var StoreGroup|MockObject
     */
    private $storeGroupMock;

    protected function setUp(): void
    {
        $this->indexerRegistryMock = $this->getMockBuilder(IndexerRegistry::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->indexerMock = $this->getMockBuilder(IndexerInterface::class)
            ->getMockForAbstractClass();
        $this->subjectMock = $this->getMockBuilder(StoreGroupResourceModel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->storeGroupMock = $this->getMockBuilder(StoreGroup::class)
            ->disableOriginalConstructor()
            ->setMethods(['dataHasChangedFor', 'isObjectNew'])
            ->getMock();

        $this->plugin = new StoreGroupIndexerPlugin($this->indexerRegistryMock);
    }

    /**
     * @param bool $isObjectNew
     * @param bool $websiteChanged
     * @param int $invalidateCounter
     * @return void
     * @dataProvider afterSaveDataProvider
     */
    public function testAfterSave(bool $isObjectNew, bool $websiteChanged, int $invalidateCounter): void
    {
        $this->prepareIndexer($invalidateCounter);
        $this->storeGroupMock->expects(static::any())
            ->method('dataHasChangedFor')
            ->with('website_id')
            ->willReturn($websiteChanged);
        $this->storeGroupMock->expects(static::once())
            ->method('isObjectNew')
            ->willReturn($isObjectNew);
        $this->indexerMock->expects(static::exactly($invalidateCounter))
            ->method('invalidate');

        $this->assertSame(
            $this->subjectMock,
            $this->plugin->afterSave($this->subjectMock, $this->subjectMock, $this->storeGroupMock)
        );
    }

    /**
     * @return array
     */
    public function afterSaveDataProvider(): array
    {
        return [
            [false, false, 0],
            [false, true, 1],
            [true, false, 0],
            [true, true, 0]
        ];
    }

    public function testAfterDelete(): void
    {
        $this->prepareIndexer(1);
        $this->indexerMock->expects(static::once())
            ->method('invalidate');

        $this->assertSame(
            $this->subjectMock,
            $this->plugin->afterDelete($this->subjectMock, $this->subjectMock)
        );
    }

    /**
     * Prepare expectations for indexer
     *
     * @param int $invalidateCounter
     * @return void
     */
    private function prepareIndexer(int $invalidateCounter): void
    {
        $this->indexerRegistryMock->expects(static::exactly($invalidateCounter))
            ->method('get')
            ->with(FulltextIndexer::INDEXER_ID)
            ->willReturn($this->indexerMock);
    }
}
