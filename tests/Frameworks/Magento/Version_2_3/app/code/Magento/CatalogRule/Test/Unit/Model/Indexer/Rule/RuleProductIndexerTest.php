<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\CatalogRule\Test\Unit\Model\Indexer\Rule;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class RuleProductIndexerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\CatalogRule\Model\Indexer\IndexBuilder|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $indexBuilder;

    /**
     * @var \Magento\CatalogRule\Model\Indexer\Rule\RuleProductIndexer
     */
    protected $indexer;

    /**
     * @var \Magento\Framework\Indexer\CacheContext|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $cacheContextMock;

    protected function setUp(): void
    {
        $this->indexBuilder = $this->createMock(\Magento\CatalogRule\Model\Indexer\IndexBuilder::class);

        $this->indexer = (new ObjectManager($this))->getObject(
            \Magento\CatalogRule\Model\Indexer\Rule\RuleProductIndexer::class,
            [
                'indexBuilder' => $this->indexBuilder,
            ]
        );

        $this->cacheContextMock = $this->createMock(\Magento\Framework\Indexer\CacheContext::class);

        $cacheContextProperty = new \ReflectionProperty(
            \Magento\CatalogRule\Model\Indexer\Rule\RuleProductIndexer::class,
            'cacheContext'
        );
        $cacheContextProperty->setAccessible(true);
        $cacheContextProperty->setValue($this->indexer, $this->cacheContextMock);
    }

    public function testDoExecuteList()
    {
        $ids = [1, 2, 5];
        $this->indexBuilder->expects($this->once())->method('reindexFull');
        $this->cacheContextMock->expects($this->once())
            ->method('registerTags')
            ->with(
                [
                    \Magento\Catalog\Model\Category::CACHE_TAG,
                    \Magento\Catalog\Model\Product::CACHE_TAG,
                    \Magento\Framework\App\Cache\Type\Block::CACHE_TAG
                ]
            );
        $this->indexer->executeList($ids);
    }

    public function testDoExecuteRow()
    {
        $this->indexBuilder->expects($this->once())->method('reindexFull');

        $this->indexer->executeRow(5);
    }
}
