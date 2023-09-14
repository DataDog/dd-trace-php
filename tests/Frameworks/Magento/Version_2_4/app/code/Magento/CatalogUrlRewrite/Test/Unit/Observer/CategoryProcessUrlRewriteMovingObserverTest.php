<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CatalogUrlRewrite\Test\Unit\Observer;

use Magento\Catalog\Model\Category;
use Magento\CatalogUrlRewrite\Block\UrlKeyRenderer;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\CatalogUrlRewrite\Model\Map\DatabaseMapPool;
use Magento\CatalogUrlRewrite\Model\Map\DataCategoryUrlRewriteDatabaseMap;
use Magento\CatalogUrlRewrite\Model\Map\DataProductUrlRewriteDatabaseMap;
use Magento\CatalogUrlRewrite\Model\UrlRewriteBunchReplacer;
use Magento\CatalogUrlRewrite\Observer\CategoryProcessUrlRewriteMovingObserver;
use Magento\CatalogUrlRewrite\Observer\UrlRewriteHandler;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CategoryProcessUrlRewriteMovingObserverTest extends TestCase
{
    /**
     * @var CategoryProcessUrlRewriteMovingObserver
     */
    private $observer;

    /**
     * @var CategoryUrlRewriteGenerator|MockObject
     */
    private $categoryUrlRewriteGeneratorMock;

    /**
     * @var UrlPersistInterface|MockObject
     */
    private $urlPersistMock;

    /**
     * @var ScopeConfigInterface|MockObject
     */
    private $scopeConfigMock;

    /**
     * @var UrlRewriteHandler|MockObject
     */
    private $urlRewriteHandlerMock;

    /**
     * @var DatabaseMapPool|MockObject
     */
    private $databaseMapPoolMock;

    /**
     * Set Up
     */
    protected function setUp(): void
    {
        $this->categoryUrlRewriteGeneratorMock = $this->createMock(CategoryUrlRewriteGenerator::class);
        $this->urlPersistMock = $this->getMockForAbstractClass(UrlPersistInterface::class);
        $this->scopeConfigMock = $this->getMockForAbstractClass(ScopeConfigInterface::class);
        $this->urlRewriteHandlerMock = $this->createMock(UrlRewriteHandler::class);
        /** @var UrlRewriteBunchReplacer|MockObject $urlRewriteMock */
        $urlRewriteMock = $this->createMock(UrlRewriteBunchReplacer::class);
        $this->databaseMapPoolMock = $this->createMock(DatabaseMapPool::class);

        $this->observer = new CategoryProcessUrlRewriteMovingObserver(
            $this->categoryUrlRewriteGeneratorMock,
            $this->urlPersistMock,
            $this->scopeConfigMock,
            $this->urlRewriteHandlerMock,
            $urlRewriteMock,
            $this->databaseMapPoolMock,
            [
                DataCategoryUrlRewriteDatabaseMap::class,
                DataProductUrlRewriteDatabaseMap::class
            ]
        );
    }

    /**
     * Test category process rewrite url by changing the parent
     *
     * @return void
     */
    public function testCategoryProcessUrlRewriteAfterMovingWithChangedParentId()
    {
        /** @var Observer|MockObject $observerMock */
        $observerMock = $this->createMock(Observer::class);
        $eventMock = $this->getMockBuilder(Event::class)
            ->disableOriginalConstructor()
            ->setMethods(['getCategory'])
            ->getMock();
        $categoryMock = $this->createPartialMock(
            Category::class,
            [
                'dataHasChangedFor',
                'getEntityId',
                'getStoreId',
                'setData'
            ]
        );

        $categoryMock->expects($this->once())->method('dataHasChangedFor')->with('parent_id')
            ->willReturn(true);
        $eventMock->expects($this->once())->method('getCategory')->willReturn($categoryMock);
        $observerMock->expects($this->once())->method('getEvent')->willReturn($eventMock);
        $this->scopeConfigMock->expects($this->once())->method('isSetFlag')
            ->with(UrlKeyRenderer::XML_PATH_SEO_SAVE_HISTORY)->willReturn(true);
        $this->scopeConfigMock->method('getValue')->willReturn(true);
        $this->categoryUrlRewriteGeneratorMock->expects($this->once())->method('generate')
            ->with($categoryMock, true)->willReturn(['category-url-rewrite']);
        $this->urlRewriteHandlerMock->expects($this->once())->method('generateProductUrlRewrites')
            ->with($categoryMock)->willReturn(['product-url-rewrite']);
        $this->databaseMapPoolMock->expects($this->exactly(2))->method('resetMap')->willReturnSelf();

        $this->observer->execute($observerMock);
    }

    /**
     * Test category process rewrite url without changing the parent
     *
     * @return void
     */
    public function testCategoryProcessUrlRewriteAfterMovingWithinNotChangedParent()
    {
        /** @var Observer|MockObject $observerMock */
        $observerMock = $this->createMock(Observer::class);
        $eventMock = $this->getMockBuilder(Event::class)
            ->disableOriginalConstructor()
            ->setMethods(['getCategory'])
            ->getMock();
        $categoryMock = $this->createPartialMock(Category::class, ['dataHasChangedFor']);
        $observerMock->expects($this->once())->method('getEvent')->willReturn($eventMock);
        $eventMock->expects($this->once())->method('getCategory')->willReturn($categoryMock);
        $categoryMock->expects($this->once())->method('dataHasChangedFor')->with('parent_id')
            ->willReturn(false);

        $this->observer->execute($observerMock);
    }
}
