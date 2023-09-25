<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Cms\Test\Unit\Model\ResourceModel\Block\Relation\Store;

use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Model\ResourceModel\Block;
use Magento\Cms\Model\ResourceModel\Block\Relation\Store\SaveHandler;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\EntityMetadata;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SaveHandlerTest extends TestCase
{
    /**
     * @var SaveHandler
     */
    protected $model;

    /**
     * @var MetadataPool|MockObject
     */
    protected $metadataPool;

    /**
     * @var Block|MockObject
     */
    protected $resourceBlock;

    protected function setUp(): void
    {
        $this->metadataPool = $this->getMockBuilder(MetadataPool::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->resourceBlock = $this->getMockBuilder(Block::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->model = new SaveHandler(
            $this->metadataPool,
            $this->resourceBlock
        );
    }

    public function testExecute()
    {
        $entityId = 1;
        $linkId = 2;
        $oldStore = 1;
        $newStore = 2;
        $linkField = 'link_id';

        $adapter = $this->getMockBuilder(AdapterInterface::class)
            ->getMockForAbstractClass();

        $whereForDelete = [
            $linkField . ' = ?' => $linkId,
            'store_id IN (?)' => [$oldStore],
        ];
        $adapter->expects($this->once())
            ->method('delete')
            ->with('cms_block_store', $whereForDelete)
            ->willReturnSelf();

        $whereForInsert = [
            $linkField => $linkId,
            'store_id' => $newStore,
        ];
        $adapter->expects($this->once())
            ->method('insertMultiple')
            ->with('cms_block_store', [$whereForInsert])
            ->willReturnSelf();

        $entityMetadata = $this->getMockBuilder(EntityMetadata::class)
            ->disableOriginalConstructor()
            ->getMock();
        $entityMetadata->expects($this->once())
            ->method('getEntityConnection')
            ->willReturn($adapter);
        $entityMetadata->expects($this->once())
            ->method('getLinkField')
            ->willReturn($linkField);

        $this->metadataPool->expects($this->once())
            ->method('getMetadata')
            ->with(BlockInterface::class)
            ->willReturn($entityMetadata);

        $this->resourceBlock->expects($this->once())
            ->method('lookupStoreIds')
            ->willReturn([$oldStore]);
        $this->resourceBlock->expects($this->once())
            ->method('getTable')
            ->with('cms_block_store')
            ->willReturn('cms_block_store');

        $block = $this->getMockBuilder(\Magento\Cms\Model\Block::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getStores',
                'getId',
                'getData',
            ])
            ->getMock();
        $block->expects($this->once())
            ->method('getStores')
            ->willReturn($newStore);
        $block->expects($this->once())
            ->method('getId')
            ->willReturn($entityId);
        $block->expects($this->exactly(2))
            ->method('getData')
            ->with($linkField)
            ->willReturn($linkId);

        $result = $this->model->execute($block);
        $this->assertInstanceOf(BlockInterface::class, $result);
    }
}
