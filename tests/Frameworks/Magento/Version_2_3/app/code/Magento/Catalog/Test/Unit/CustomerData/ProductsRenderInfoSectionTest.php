<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\CustomerData;

use Magento\Catalog\Api\Data\ProductFrontendActionInterface;
use Magento\Catalog\Api\Data\ProductRenderInterface;
use Magento\Catalog\Api\Data\ProductRenderSearchResultsInterface;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\Search\SearchCriteria;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Store\Model\Store;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ProductsRenderInfoSectionTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Catalog\CustomerData\ProductsRenderInfoSection */
    protected $model;

    /** @var ObjectManagerHelper */
    protected $objectManagerHelper;

    /** @var \Magento\Store\Model\StoreManager|\PHPUnit\Framework\MockObject\MockObject */
    protected $storeManagerMock;

    /** @var \Magento\Framework\Api\SearchCriteriaBuilder|\PHPUnit\Framework\MockObject\MockObject */
    protected $searchCriteriaBuilderMock;

    /** @var \Magento\Framework\Api\FilterBuilder|\PHPUnit\Framework\MockObject\MockObject */
    protected $filterBuilderMock;

    /** @var \Magento\Catalog\Model\ProductRenderList |\PHPUnit\Framework\MockObject\MockObject */
    protected $productRenderRepositoryMock;

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    protected $synchronizerMock;

    /** @var \Magento\Framework\EntityManager\Hydrator|\PHPUnit\Framework\MockObject\MockObject */
    protected $hydratorMock;

    protected function setUp(): void
    {
        $this->storeManagerMock = $this->getMockBuilder(\Magento\Store\Model\StoreManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->searchCriteriaBuilderMock = $this
            ->getMockBuilder(\Magento\Framework\Api\SearchCriteriaBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->filterBuilderMock = $this->getMockBuilder(\Magento\Framework\Api\FilterBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->productRenderRepositoryMock = $this
            ->getMockBuilder(\Magento\Catalog\Model\ProductRenderList::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->synchronizerMock = $this
            ->getMockBuilder(\Magento\Catalog\Model\Product\ProductFrontendAction\Synchronizer::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->hydratorMock = $this->getMockBuilder(\Magento\Framework\EntityManager\Hydrator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->model = $this->objectManagerHelper->getObject(
            \Magento\Catalog\CustomerData\ProductsRenderInfoSection::class,
            [
                'storeManager' => $this->storeManagerMock,
                'searchCriteriaBuilder' => $this->searchCriteriaBuilderMock,
                'filterBuilder' => $this->filterBuilderMock,
                'productRenderList' => $this->productRenderRepositoryMock,
                'actionsSynchronizer' => $this->synchronizerMock,
                'hydrator' => $this->hydratorMock
            ]
        );
    }

    private function prepareProductIds()
    {
        $actionFirst = $this->getMockForAbstractClass(ProductFrontendActionInterface::class);
        $actionSecond = $this->getMockForAbstractClass(ProductFrontendActionInterface::class);
        $actions = [$actionFirst, $actionSecond];
        $this->synchronizerMock->expects($this->once())
            ->method('getAllActions')
            ->willReturn($actions);
        $actionFirst->expects($this->any())
            ->method('getProductId')
            ->willReturn(1);
        $actionSecond->expects($this->any())
            ->method('getProductId')
            ->willReturn(2);
    }

    public function testGetSectionData()
    {
        $productRender = $this->getMockForAbstractClass(ProductRenderInterface::class);
        $searchResult = $this->getMockForAbstractClass(ProductRenderSearchResultsInterface::class);

        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $store->expects($this->once())
            ->method('getId')
            ->willReturn(3);
        $store->expects($this->once())
            ->method('getCurrentCurrencyCode')
            ->willReturn('UAH');
        $this->storeManagerMock->expects($this->once())
            ->method('getStore')
            ->willReturn($store);
        $filterMock = $this->createMock(Filter::class);
        $this->filterBuilderMock
            ->expects($this->once())
            ->method('setField')
            ->with('entity_id')
            ->willReturnSelf();
        $this->prepareProductIds();
        $this->filterBuilderMock
            ->expects($this->once())
            ->method('setValue')
            ->with([1, 2])
            ->willReturnSelf();
        $this->filterBuilderMock
            ->expects($this->once())
            ->method('setConditionType')
            ->with('in')
            ->willReturnSelf();
        $this->filterBuilderMock
            ->expects($this->once())
            ->method('create')
            ->willReturn($filterMock);
        $searchCriteria = $this->createMock(SearchCriteria::class);
        $this->searchCriteriaBuilderMock
            ->expects($this->once())
            ->method('addFilters')
            ->with([$filterMock])
            ->willReturnSelf();
        $this->searchCriteriaBuilderMock->expects($this->once())
            ->method('create')
            ->willReturn($searchCriteria);
        $this->productRenderRepositoryMock->expects($this->once())
            ->method('getList')
            ->with($searchCriteria, 3, 'UAH')
            ->willReturn($searchResult);
        $searchResult->expects($this->any())
            ->method('getItems')
            ->willReturn([$productRender]);
        $this->hydratorMock->expects($this->once())
            ->method('extract')
            ->with($productRender)
            ->willReturn(
                [
                    'name' => 'One',
                    'price_info' => [
                        'final_price' => 12
                    ]
                ]
            );

        $productRender->expects($this->once())
            ->method('getId')
            ->willReturn(1);

        $this->assertEquals(
            [
                1 => [
                    'name' => 'One',
                    'price_info' => [
                        'final_price' => 12
                    ]
                ]
            ],
            $this->model->getSectionData()
        );
    }
}
