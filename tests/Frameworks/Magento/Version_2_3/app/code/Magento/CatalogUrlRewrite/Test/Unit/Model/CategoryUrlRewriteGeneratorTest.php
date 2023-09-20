<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\CatalogUrlRewrite\Test\Unit\Model;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * Class CategoryUrlRewriteGeneratorTest
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CategoryUrlRewriteGeneratorTest extends \PHPUnit\Framework\TestCase
{
    /** @var \PHPUnit\Framework\MockObject\MockObject */
    private $canonicalUrlRewriteGenerator;

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    private $currentUrlRewritesRegenerator;

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    private $childrenUrlRewriteGenerator;

    /** @var \Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator */
    private $categoryUrlRewriteGenerator;

    /** @var \Magento\CatalogUrlRewrite\Service\V1\StoreViewService|\PHPUnit\Framework\MockObject\MockObject */
    private $storeViewService;

    /** @var \Magento\Catalog\Model\Category|\PHPUnit\Framework\MockObject\MockObject */
    private $category;

    /** @var \Magento\Catalog\Api\CategoryRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $categoryRepository;

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    private $mergeDataProvider;

    /** @var \Magento\Framework\Serialize\Serializer\Json|\PHPUnit\Framework\MockObject\MockObject */
    protected $serializer;

    /**
     * Test method
     */
    protected function setUp(): void
    {
        $this->serializer = $this->createMock(\Magento\Framework\Serialize\Serializer\Json::class);
        $this->serializer->expects($this->any())
            ->method('serialize')
            ->willReturnCallback(
                function ($value) {
                    return json_encode($value);
                }
            );
        $this->serializer->expects($this->any())
            ->method('unserialize')
            ->willReturnCallback(
                function ($value) {
                    return json_decode($value, true);
                }
            );
        
        $this->currentUrlRewritesRegenerator = $this->getMockBuilder(
            \Magento\CatalogUrlRewrite\Model\Category\CurrentUrlRewritesRegenerator::class
        )->disableOriginalConstructor()->getMock();
        $this->canonicalUrlRewriteGenerator = $this->getMockBuilder(
            \Magento\CatalogUrlRewrite\Model\Category\CanonicalUrlRewriteGenerator::class
        )->disableOriginalConstructor()->getMock();
        $this->childrenUrlRewriteGenerator = $this->getMockBuilder(
            \Magento\CatalogUrlRewrite\Model\Category\ChildrenUrlRewriteGenerator::class
        )->disableOriginalConstructor()->getMock();
        $this->storeViewService = $this->getMockBuilder(\Magento\CatalogUrlRewrite\Service\V1\StoreViewService::class)
            ->disableOriginalConstructor()->getMock();
        $this->category = $this->createMock(\Magento\Catalog\Model\Category::class);
        $this->categoryRepository = $this->createMock(\Magento\Catalog\Api\CategoryRepositoryInterface::class);
        $mergeDataProviderFactory = $this->createPartialMock(
            \Magento\UrlRewrite\Model\MergeDataProviderFactory::class,
            ['create']
        );
        $this->mergeDataProvider = new \Magento\UrlRewrite\Model\MergeDataProvider;
        $mergeDataProviderFactory->expects($this->once())->method('create')->willReturn($this->mergeDataProvider);

        $this->categoryUrlRewriteGenerator = (new ObjectManager($this))->getObject(
            \Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator::class,
            [
                'canonicalUrlRewriteGenerator' => $this->canonicalUrlRewriteGenerator,
                'childrenUrlRewriteGenerator' => $this->childrenUrlRewriteGenerator,
                'currentUrlRewritesRegenerator' => $this->currentUrlRewritesRegenerator,
                'storeViewService' => $this->storeViewService,
                'categoryRepository' => $this->categoryRepository,
                'mergeDataProviderFactory' => $mergeDataProviderFactory
            ]
        );
    }

    /**
     * Test method
     */
    public function testGenerationForGlobalScope()
    {
        $categoryId = 1;
        $this->category->expects($this->any())->method('getStoreId')->willReturn(null);
        $this->category->expects($this->any())->method('getStoreIds')->willReturn([1]);
        $this->storeViewService->expects($this->once())->method('doesEntityHaveOverriddenUrlKeyForStore')
            ->willReturn(false);
        $canonical = new \Magento\UrlRewrite\Service\V1\Data\UrlRewrite([], $this->serializer);
        $canonical->setRequestPath('category-1')
            ->setStoreId(1);
        $this->canonicalUrlRewriteGenerator->expects($this->any())->method('generate')
            ->willReturn(['category-1' => $canonical]);
        $children1 = new \Magento\UrlRewrite\Service\V1\Data\UrlRewrite([], $this->serializer);
        $children1->setRequestPath('category-2')
            ->setStoreId(2);
        $children2 = new \Magento\UrlRewrite\Service\V1\Data\UrlRewrite([], $this->serializer);
        $children2->setRequestPath('category-22')
            ->setStoreId(2);
        $this->childrenUrlRewriteGenerator->expects($this->any())->method('generate')
            ->with(1, $this->category, $categoryId)
            ->willReturn(['category-2' => $children1, 'category-1' => $children2]);
        $current = new \Magento\UrlRewrite\Service\V1\Data\UrlRewrite([], $this->serializer);
        $current->setRequestPath('category-3')
            ->setStoreId(3);
        $this->currentUrlRewritesRegenerator->expects($this->any())->method('generate')
            ->with(1, $this->category, $categoryId)
            ->willReturn(['category-3' => $current]);
        $categoryForSpecificStore = $this->createPartialMock(
            \Magento\Catalog\Model\Category::class,
            ['getUrlKey', 'getUrlPath']
        );
        $this->categoryRepository->expects($this->once())->method('get')->willReturn($categoryForSpecificStore);

        $this->assertEquals(
            [
                'category-1_1' => $canonical,
                'category-2_2' => $children1,
                'category-22_2' => $children2,
                'category-3_3' => $current
            ],
            $this->categoryUrlRewriteGenerator->generate($this->category, false, $categoryId)
        );
    }

    /**
     * Test method
     */
    public function testGenerationForSpecificStore()
    {
        $this->category->expects($this->any())->method('getStoreId')->willReturn(1);
        $this->category->expects($this->never())->method('getStoreIds');
        $canonical = new \Magento\UrlRewrite\Service\V1\Data\UrlRewrite([], $this->serializer);
        $canonical->setRequestPath('category-1')
            ->setStoreId(1);
        $this->canonicalUrlRewriteGenerator->expects($this->any())->method('generate')
            ->willReturn([$canonical]);
        $this->childrenUrlRewriteGenerator->expects($this->any())->method('generate')
            ->willReturn([]);
        $this->currentUrlRewritesRegenerator->expects($this->any())->method('generate')
            ->willReturn([]);

        $this->assertEquals(
            ['category-1_1' => $canonical],
            $this->categoryUrlRewriteGenerator->generate($this->category, 1)
        );
    }

    /**
     * Test method
     */
    public function testSkipGenerationForGlobalScope()
    {
        $this->category->expects($this->any())->method('getStoreIds')->willReturn([1, 2]);
        $this->storeViewService->expects($this->exactly(2))->method('doesEntityHaveOverriddenUrlKeyForStore')
            ->willReturn(true);

        $this->assertEquals([], $this->categoryUrlRewriteGenerator->generate($this->category));
    }

    /**
     * Test method
     */
    public function testSkipGenerationForGlobalScopeWithCategory()
    {
        $this->category->expects($this->any())->method('getStoreIds')->willReturn([1, 2]);
        $this->category->expects($this->any())->method('getEntityId')->willReturn(1);
        $this->category->expects($this->any())->method('getStoreId')->willReturn(false);
        $this->storeViewService->expects($this->exactly(2))->method('doesEntityHaveOverriddenUrlKeyForStore')
            ->willReturn(true);

        $this->assertEquals([], $this->categoryUrlRewriteGenerator->generate($this->category, false, 1));
    }
}
