<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Test\Unit\Block\Ui;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductRenderInterface;
use Magento\Catalog\Block\Ui\ProductViewCounter;
use Magento\Catalog\Model\ProductRenderFactory;
use Magento\Catalog\Model\ProductRepository;
use Magento\Catalog\Ui\DataProvider\Product\ProductRenderCollectorComposite;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\EntityManager\Hydrator;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Url;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManager;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ProductViewCounterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Block\Ui\ProductViewCounter|\PHPUnit\Framework\MockObject\MockObject
     */
    private $productViewCounter;

    /**
     * @var Context|\PHPUnit\Framework\MockObject\MockObject
     */
    private $contextMock;

    /**
     * @var ProductRepository|\PHPUnit\Framework\MockObject\MockObject
     */
    private $productRepositoryMock;

    /**
     * @var ProductRenderCollectorComposite|\PHPUnit\Framework\MockObject\MockObject
     */
    private $productRenderCollectorCompositeMock;

    /**
     * @var Hydrator|\PHPUnit\Framework\MockObject\MockObject
     */
    private $hydratorMock;

    /**
     * @var SerializerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $serializeMock;

    /**
     * @var Url|\PHPUnit\Framework\MockObject\MockObject
     */
    private $urlMock;

    /**
     * @var Registry|\PHPUnit\Framework\MockObject\MockObject
     */
    private $registryMock;

    /**
     * @var StoreManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private $storeManagerMock;

    /**
     * @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $scopeConfigMock;

    /**
     * @var ProductRenderFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $productRenderFactoryMock;

    protected function setUp(): void
    {
        $this->contextMock = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->productRepositoryMock = $this->getMockBuilder(ProductRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->productRenderCollectorCompositeMock = $this->getMockBuilder(ProductRenderCollectorComposite::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->productRenderFactoryMock = $this->getMockBuilder(ProductRenderFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->hydratorMock = $this->getMockBuilder(Hydrator::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->serializeMock = $this->getMockBuilder(SerializerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->urlMock = $this->getMockBuilder(Url::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->registryMock = $this->getMockBuilder(Registry::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->storeManagerMock = $this->getMockBuilder(StoreManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->scopeConfigMock = $this->getMockBuilder(ScopeConfigInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->productViewCounter = new ProductViewCounter(
            $this->contextMock,
            $this->productRepositoryMock,
            $this->productRenderCollectorCompositeMock,
            $this->storeManagerMock,
            $this->productRenderFactoryMock,
            $this->hydratorMock,
            $this->serializeMock,
            $this->urlMock,
            $this->registryMock,
            $this->scopeConfigMock
        );
    }

    public function testGetCurrentProductDataWithEmptyProduct()
    {
        $productMock = $this->getMockBuilder(ProductInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $storeMock = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->registryMock->expects($this->once())
            ->method('registry')
            ->with('product')
            ->willReturn($productMock);

        $this->storeManagerMock->expects($this->once())
            ->method('getStore')
            ->willReturn($storeMock);
        $storeMock->expects($this->once())
            ->method('getId')
            ->willReturn(1);
        $storeMock->expects($this->once())
            ->method('getCurrentCurrency')
            ->willReturn($storeMock);
        $storeMock->expects($this->once())
            ->method('getCode')
            ->willReturn('USD');

        $this->productViewCounter->getCurrentProductData();
    }

    public function testGetCurrentProductDataWithNonEmptyProduct()
    {
        $productMock = $this->getMockBuilder(ProductInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $productRendererMock = $this->getMockBuilder(ProductRenderInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $storeMock = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->registryMock->expects($this->once())
            ->method('registry')
            ->with('product')
            ->willReturn($productMock);
        $this->productRenderFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($productRendererMock);
        $productMock->expects($this->exactly(2))
            ->method('getId')
            ->willReturn(123);
        $this->productRenderCollectorCompositeMock->expects($this->once())
            ->method('collect')
            ->with($productMock, $productRendererMock);
        $this->storeManagerMock->expects($this->once())
            ->method('getStore')
            ->willReturn($storeMock);
        $storeMock->expects($this->exactly(2))
            ->method('getId')
            ->willReturn(1);
        $storeMock->expects($this->once())
            ->method('getCurrentCurrency')
            ->willReturn($storeMock);
        $storeMock->expects($this->once())
            ->method('getCode')
            ->willReturn('USD');

        $this->productViewCounter->getCurrentProductData();
    }
}
