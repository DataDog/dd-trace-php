<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\SalesInventory\Test\Unit\Observer;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\OrderRepository;
use Magento\SalesInventory\Model\Order\ReturnProcessor;
use Magento\SalesInventory\Observer\RefundOrderInventoryObserver;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RefundOrderInventoryObserverTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var RefundOrderInventoryObserver
     */
    protected $observer;

    /**
     * @var \Magento\Catalog\Model\Indexer\Product\Price\Processor|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $priceIndexer;

    /**
     * @var \Magento\CatalogInventory\Model\Indexer\Stock\Processor|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $stockIndexerProcessor;

    /**
     * @var \Magento\CatalogInventory\Api\StockManagementInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $stockManagement;

    /**
     * @var \Magento\CatalogInventory\Api\StockConfigurationInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $stockConfiguration;

    /**
     * @var \Magento\Framework\Event|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $event;

    /**
     * @var \Magento\Framework\Event\Observer|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $eventObserver;

    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManagerHelper;

    /**
     * @var OrderRepository|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $orderRepositoryMock;

    /**
     * @var ReturnProcessor|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $returnProcessorMock;

    /**
     * @var OrderInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orderMock;

    protected function setUp(): void
    {
        $this->stockIndexerProcessor = $this->createPartialMock(
            \Magento\CatalogInventory\Model\Indexer\Stock\Processor::class,
            ['reindexList']
        );

        $this->stockManagement = $this->createMock(\Magento\CatalogInventory\Model\StockManagement::class);

        $this->stockConfiguration = $this->getMockForAbstractClass(
            \Magento\CatalogInventory\Api\StockConfigurationInterface::class,
            [
                'isAutoReturnEnabled',
                'isDisplayProductStockStatus'
            ],
            '',
            false
        );

        $this->priceIndexer = $this->getMockBuilder(\Magento\Catalog\Model\Indexer\Product\Price\Processor::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->event = $this->getMockBuilder(\Magento\Framework\Event::class)
            ->disableOriginalConstructor()
            ->setMethods(['getProduct', 'getCollection', 'getCreditmemo', 'getQuote', 'getWebsite'])
            ->getMock();

        $this->eventObserver = $this->getMockBuilder(\Magento\Framework\Event\Observer::class)
            ->disableOriginalConstructor()
            ->setMethods(['getEvent'])
            ->getMock();

        $this->eventObserver->expects($this->atLeastOnce())
            ->method('getEvent')
            ->willReturn($this->event);

        $this->orderRepositoryMock = $this->getMockBuilder(OrderRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->returnProcessorMock = $this->getMockBuilder(ReturnProcessor::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->orderMock = $this->getMockBuilder(OrderInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->observer = $this->objectManagerHelper->getObject(
            \Magento\SalesInventory\Observer\RefundOrderInventoryObserver::class,
            [
                'stockConfiguration' => $this->stockConfiguration,
                'stockManagement' => $this->stockManagement,
                'stockIndexerProcessor' => $this->stockIndexerProcessor,
                'priceIndexer' => $this->priceIndexer,
            ]
        );

        $this->objectManagerHelper->setBackwardCompatibleProperty(
            $this->observer,
            'orderRepository',
            $this->orderRepositoryMock
        );
        $this->objectManagerHelper->setBackwardCompatibleProperty(
            $this->observer,
            'returnProcessor',
            $this->returnProcessorMock
        );
    }

    public function testRefundOrderInventory()
    {
        $ids = ['1', '14'];
        $items = [];

        $creditMemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);

        foreach ($ids as $id) {
            $item = $this->getCreditMemoItem($id);
            $items[] = $item;
        }

        $creditMemo->expects($this->once())
            ->method('getItems')
            ->willReturn($items);
        $this->event->expects($this->once())
            ->method('getCreditmemo')
            ->willReturn($creditMemo);

        $this->orderRepositoryMock->expects($this->once())
            ->method('get')
            ->willReturn($this->orderMock);

        $this->returnProcessorMock->expects($this->once())
            ->method('execute')
            ->with($creditMemo, $this->orderMock, $ids);

        $this->observer->execute($this->eventObserver);
    }

    /**
     * @param $productId
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function getCreditMemoItem($productId)
    {
        $backToStock = true;
        $item = $this->createPartialMock(
            \Magento\Sales\Model\Order\Creditmemo\Item::class,
            ['getOrderItemId', 'getBackToStock', 'getQty', '__wakeup']
        );
        $item->expects($this->any())->method('getBackToStock')->willReturn($backToStock);
        $item->expects($this->any())->method('getOrderItemId')->willReturn($productId);
        return $item;
    }
}
