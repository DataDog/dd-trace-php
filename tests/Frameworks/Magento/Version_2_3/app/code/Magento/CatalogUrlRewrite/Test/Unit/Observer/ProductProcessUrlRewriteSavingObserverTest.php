<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\CatalogUrlRewrite\Test\Unit\Observer;

use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;

/**
 * Class ProductProcessUrlRewriteSavingObserverTest
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ProductProcessUrlRewriteSavingObserverTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\UrlRewrite\Model\UrlPersistInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $urlPersist;

    /**
     * @var \Magento\Framework\Event|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $event;

    /**
     * @var \Magento\Framework\Event\Observer|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $observer;

    /**
     * @var \Magento\Catalog\Model\Product|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $product;

    /**
     * @var \Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $productUrlRewriteGenerator;

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var \Magento\CatalogUrlRewrite\Observer\ProductProcessUrlRewriteSavingObserver
     */
    protected $model;

    /**
     * Set up
     */
    protected function setUp(): void
    {
        $this->urlPersist = $this->createMock(\Magento\UrlRewrite\Model\UrlPersistInterface::class);
        $this->product = $this->createPartialMock(\Magento\Catalog\Model\Product::class, [
                'getId',
                'dataHasChangedFor',
                'isVisibleInSiteVisibility',
                'getIsChangedWebsites',
                'getIsChangedCategories',
                'getStoreId'
            ]);
        $this->product->expects($this->any())->method('getId')->willReturn(3);
        $this->event = $this->createPartialMock(\Magento\Framework\Event::class, ['getProduct']);
        $this->event->expects($this->any())->method('getProduct')->willReturn($this->product);
        $this->observer = $this->createPartialMock(\Magento\Framework\Event\Observer::class, ['getEvent']);
        $this->observer->expects($this->any())->method('getEvent')->willReturn($this->event);
        $this->productUrlRewriteGenerator = $this->createPartialMock(
            \Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator::class,
            ['generate']
        );
        $this->productUrlRewriteGenerator->expects($this->any())
            ->method('generate')
            ->willReturn([3 => 'rewrite']);
        $this->objectManager = new ObjectManager($this);
        $this->model = $this->objectManager->getObject(
            \Magento\CatalogUrlRewrite\Observer\ProductProcessUrlRewriteSavingObserver::class,
            [
                'productUrlRewriteGenerator' => $this->productUrlRewriteGenerator,
                'urlPersist' => $this->urlPersist
            ]
        );
    }

    /**
     * Data provider
     *
     * @return array
     */
    public function urlKeyDataProvider()
    {
        return [
            'url changed' => [
                'isChangedUrlKey'       => true,
                'isChangedVisibility'   => false,
                'isChangedWebsites'     => false,
                'isChangedCategories'   => false,
                'visibilityResult'      => true,
                'expectedReplaceCount'  => 1,

            ],
            'no chnages' => [
                'isChangedUrlKey'       => false,
                'isChangedVisibility'   => false,
                'isChangedWebsites'     => false,
                'isChangedCategories'   => false,
                'visibilityResult'      => true,
                'expectedReplaceCount'  => 0
            ],
            'visibility changed' => [
                'isChangedUrlKey'       => false,
                'isChangedVisibility'   => true,
                'isChangedWebsites'     => false,
                'isChangedCategories'   => false,
                'visibilityResult'      => true,
                'expectedReplaceCount'  => 1
            ],
            'websites changed' => [
                'isChangedUrlKey'       => false,
                'isChangedVisibility'   => false,
                'isChangedWebsites'     => true,
                'isChangedCategories'   => false,
                'visibilityResult'      => true,
                'expectedReplaceCount'  => 1
            ],
            'categories changed' => [
                'isChangedUrlKey'       => false,
                'isChangedVisibility'   => false,
                'isChangedWebsites'     => false,
                'isChangedCategories'   => true,
                'visibilityResult'      => true,
                'expectedReplaceCount'  => 1
            ],
            'url changed invisible' => [
                'isChangedUrlKey'       => true,
                'isChangedVisibility'   => false,
                'isChangedWebsites'     => false,
                'isChangedCategories'   => false,
                'visibilityResult'      => false,
                'expectedReplaceCount'  => 0
            ],
        ];
    }

    /**
     * @param bool $isChangedUrlKey
     * @param bool $isChangedVisibility
     * @param bool $isChangedWebsites
     * @param bool $isChangedCategories
     * @param bool $visibilityResult
     * @param int $expectedReplaceCount
     *
     * @dataProvider urlKeyDataProvider
     */
    public function testExecuteUrlKey(
        $isChangedUrlKey,
        $isChangedVisibility,
        $isChangedWebsites,
        $isChangedCategories,
        $visibilityResult,
        $expectedReplaceCount
    ) {
        $this->product->expects($this->any())->method('getStoreId')->willReturn(12);

        $this->product->expects($this->any())
            ->method('dataHasChangedFor')
            ->willReturnMap(
                [
                    ['visibility', $isChangedVisibility],
                    ['url_key', $isChangedUrlKey]
                ]
            );

        $this->product->expects($this->any())
            ->method('getIsChangedWebsites')
            ->willReturn($isChangedWebsites);

        $this->product->expects($this->any())
            ->method('getIsChangedCategories')
            ->willReturn($isChangedCategories);

        $this->product->expects($this->any())
            ->method('isVisibleInSiteVisibility')
            ->willReturn($visibilityResult);

        $this->urlPersist->expects($this->exactly($expectedReplaceCount))
            ->method('replace')
            ->with([3 => 'rewrite']);

        $this->model->execute($this->observer);
    }
}
