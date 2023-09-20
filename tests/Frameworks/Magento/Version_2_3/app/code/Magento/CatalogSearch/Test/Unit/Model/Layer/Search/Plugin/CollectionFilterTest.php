<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogSearch\Test\Unit\Model\Layer\Search\Plugin;

use Magento\CatalogSearch\Model\Layer\Search\Plugin\CollectionFilter as CollectionFilterPlugin;
use Magento\Catalog\Model\Layer\Search\CollectionFilter;
use Magento\Catalog\Model\Category;
use Magento\Search\Model\QueryFactory;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Search\Model\Query;

class CollectionFilterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var CollectionFilterPlugin
     */
    private $plugin;

    /**
     * @var Collection|\PHPUnit\Framework\MockObject\MockObject
     */
    private $collectionMock;

    /**
     * @var Category|\PHPUnit\Framework\MockObject\MockObject
     */
    private $categoryMock;

    /**
     * @var QueryFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $queryFactoryMock;

    /**
     * @var CollectionFilter|\PHPUnit\Framework\MockObject\MockObject
     */
    private $collectionFilterMock;

    /**
     * @var Query|\PHPUnit\Framework\MockObject\MockObject
     */
    private $queryMock;

    /***
     * @var ObjectManager
     */
    private $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);

        $this->collectionMock = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->setMethods(['addSearchFilter'])
            ->getMock();
        $this->categoryMock = $this->getMockBuilder(Category::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->queryFactoryMock = $this->getMockBuilder(QueryFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['get'])
            ->getMock();
        $this->collectionFilterMock = $this->getMockBuilder(CollectionFilter::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->queryMock = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->setMethods(['isQueryTextShort', 'getQueryText'])
            ->getMock();

        $this->plugin = $this->objectManager->getObject(
            CollectionFilterPlugin::class,
            ['queryFactory' => $this->queryFactoryMock]
        );
    }

    public function testAfterFilter()
    {
        $queryText = 'Test Query';

        $this->queryFactoryMock->expects($this->once())
            ->method('get')
            ->willReturn($this->queryMock);
        $this->queryMock->expects($this->once())
            ->method('isQueryTextShort')
            ->willReturn(false);
        $this->queryMock->expects($this->once())
            ->method('getQueryText')
            ->willReturn($queryText);
        $this->collectionMock->expects($this->once())
            ->method('addSearchFilter')
            ->with($queryText);

        $this->plugin->afterFilter(
            $this->collectionFilterMock,
            null,
            $this->collectionMock,
            $this->categoryMock
        );
    }
}
