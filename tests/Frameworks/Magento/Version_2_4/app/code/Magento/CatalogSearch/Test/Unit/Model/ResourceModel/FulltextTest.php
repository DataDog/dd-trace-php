<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CatalogSearch\Test\Unit\Model\ResourceModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadata;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FulltextTest extends TestCase
{
    /**
     * @var AdapterInterface|MockObject
     */
    private $connection;

    /**
     * @var Resource|MockObject
     */
    private $resource;

    /**
     * @var Context|MockObject
     */
    private $context;

    /**
     * Holder for MetadataPool mock object.
     *
     * @var MetadataPool|MockObject
     */
    private $metadataPool;

    /**
     * @var Fulltext
     */
    private $target;

    protected function setUp(): void
    {
        $this->context = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resource = $this->getMockBuilder(ResourceConnection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->context->expects($this->once())
            ->method('getResources')
            ->willReturn($this->resource);
        $this->connection = $this->getMockBuilder(AdapterInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->resource->expects($this->once())
            ->method('getConnection')
            ->willReturn($this->connection);
        $this->metadataPool = $this->getMockBuilder(MetadataPool::class)
            ->disableOriginalConstructor()
            ->getMock();

        $objectManager = new ObjectManager($this);
        $this->target = $objectManager->getObject(
            Fulltext::class,
            [
                'context' => $this->context,
                'metadataPool' => $this->metadataPool
            ]
        );
    }

    public function testResetSearchResultByStore()
    {
        $this->resource->expects($this->once())
            ->method('getTableName')
            ->with('search_query', ResourceConnection::DEFAULT_CONNECTION)
            ->willReturn('table_name_search_query');
        $this->connection->expects($this->once())
            ->method('update')
            ->with('table_name_search_query', ['is_processed' => 0], ['is_processed != ?' => 0, 'store_id = ?' => 1])
            ->willReturn(10);
        $result = $this->target->resetSearchResultsByStore(1);
        $this->assertEquals($this->target, $result);
    }

    /**
     * @covers \Magento\CatalogSearch\Model\ResourceModel\Fulltext::getRelationsByChild()
     */
    public function testGetRelationsByChild()
    {
        $ids = [1, 2, 3];
        $testTable1 = 'testTable1';
        $testTable2 = 'testTable2';
        $fieldForParent = 'testLinkField';

        $metadata = $this->getMockBuilder(EntityMetadata::class)
            ->disableOriginalConstructor()
            ->getMock();
        $metadata->expects($this->once())
            ->method('getLinkField')
            ->willReturn($fieldForParent);

        $this->metadataPool->expects($this->once())
            ->method('getMetadata')
            ->with(ProductInterface::class)
            ->willReturn($metadata);

        $select = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->getMock();
        $select->expects($this->once())
            ->method('from')
            ->with(['relation' => $testTable1])
            ->willReturnSelf();
        $select->expects($this->once())
            ->method('distinct')
            ->with(true)
            ->willReturnSelf();
        $select->expects($this->once())
            ->method('where')
            ->with('relation.child_id IN (?)', $ids)
            ->willReturnSelf();
        $select->expects($this->once())
            ->method('join')
            ->with(
                ['cpe' => $testTable2],
                'cpe.' . $fieldForParent . ' = relation.parent_id',
                ['cpe.entity_id']
            )->willReturnSelf();

        $this->connection->expects($this->once())
            ->method('select')
            ->willReturn($select);
        $this->connection->expects($this->once())
            ->method('fetchCol')
            ->with($select)
            ->willReturn($ids);

        $this->resource->expects($this->exactly(2))
            ->method('getTableName')
            ->withConsecutive(
                ['catalog_product_relation', ResourceConnection::DEFAULT_CONNECTION],
                ['catalog_product_entity', ResourceConnection::DEFAULT_CONNECTION]
            )
            ->will($this->onConsecutiveCalls(
                $testTable1,
                $testTable2
            ));

        self::assertSame($ids, $this->target->getRelationsByChild($ids));
    }
}
