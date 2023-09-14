<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\SalesInventory\Test\Unit\Model\Order;

use Magento\CatalogInventory\Api\StockManagementInterface;
use Magento\CatalogInventory\Model\Indexer\Stock\Processor;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\CreditmemoItemInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\SalesInventory\Model\Order\ReturnProcessor;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ReturnProcessorTest extends TestCase
{
    /**
     * @var MockObject|OrderInterface
     */
    private $orderMock;

    /**
     * @var MockObject|CreditmemoInterface
     */
    private $creditmemoMock;

    /**
     * @var MockObject|StockManagementInterface
     */
    private $stockManagementMock;

    /**
     * @var MockObject|Processor
     */
    private $stockIndexerProcessorMock;

    /**
     * @var MockObject|\Magento\Catalog\Model\Indexer\Product\Price\Processor
     */
    private $priceIndexerMock;

    /**
     * @var MockObject|StoreManagerInterface
     */
    private $storeManagerMock;

    /**
     * @var MockObject|OrderItemRepositoryInterface
     */
    private $orderItemRepositoryMock;

    /**
     * @var MockObject|CreditmemoItemInterface
     */
    private $creditmemoItemMock;

    /** @var  ReturnProcessor */
    private $returnProcessor;

    /** @var  MockObject|OrderItemInterface */
    private $orderItemMock;

    /** @var  MockObject|StoreInterface */
    private $storeMock;

    protected function setUp(): void
    {
        $this->stockManagementMock = $this->getMockBuilder(StockManagementInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->stockIndexerProcessorMock = $this->getMockBuilder(
            Processor::class
        )->disableOriginalConstructor()
            ->getMock();
        $this->priceIndexerMock = $this->getMockBuilder(\Magento\Catalog\Model\Indexer\Product\Price\Processor::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->storeManagerMock = $this->getMockBuilder(StoreManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->orderItemRepositoryMock = $this->getMockBuilder(OrderRepositoryInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->orderItemRepositoryMock = $this->getMockBuilder(OrderItemRepositoryInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->orderMock = $this->getMockBuilder(OrderInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->creditmemoMock = $this->getMockBuilder(CreditmemoInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->creditmemoItemMock = $this->getMockBuilder(CreditmemoItemInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->orderItemMock = $this->getMockBuilder(OrderItemInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->storeMock = $this->getMockBuilder(StoreInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->returnProcessor = new ReturnProcessor(
            $this->stockManagementMock,
            $this->stockIndexerProcessorMock,
            $this->priceIndexerMock,
            $this->storeManagerMock,
            $this->orderItemRepositoryMock
        );
    }

    public function testExecute()
    {
        $orderItemId = 99;
        $productId = 50;
        $returnToStockItems = [$orderItemId];
        $parentItemId = 52;
        $qty = 1;
        $storeId = 0;
        $webSiteId = 10;

        $this->creditmemoMock->expects($this->once())
            ->method('getItems')
            ->willReturn([$this->creditmemoItemMock]);

        $this->creditmemoItemMock->expects($this->exactly(2))
            ->method('getOrderItemId')
            ->willReturn($orderItemId);

        $this->creditmemoItemMock->expects($this->once())
            ->method('getProductId')
            ->willReturn($productId);

        $this->orderItemRepositoryMock->expects($this->once())
            ->method('get')
            ->with($orderItemId)
            ->willReturn($this->orderItemMock);

        $this->orderMock->expects($this->once())
            ->method('getStoreId')
            ->willReturn($storeId);

        $this->storeManagerMock->expects($this->once())
            ->method('getStore')
            ->with($storeId)
            ->willReturn($this->storeMock);

        $this->storeMock->expects($this->once())
            ->method('getWebsiteId')
            ->willReturn($webSiteId);

        $this->stockManagementMock->expects($this->once())
            ->method('backItemQty')
            ->with($productId, $qty, $webSiteId)
            ->willReturn(true);

        $this->stockIndexerProcessorMock->expects($this->once())
            ->method('reindexList')
            ->with([$productId]);

        $this->priceIndexerMock->expects($this->once())
            ->method('reindexList')
            ->with([$productId]);

        $this->orderItemMock->expects($this->once())
            ->method('getParentItemId')
            ->willReturn($parentItemId);

        $this->creditmemoItemMock->expects($this->once())
            ->method('getQty')
            ->willReturn($qty);

        $this->returnProcessor->execute($this->creditmemoMock, $this->orderMock, $returnToStockItems);
    }
}
