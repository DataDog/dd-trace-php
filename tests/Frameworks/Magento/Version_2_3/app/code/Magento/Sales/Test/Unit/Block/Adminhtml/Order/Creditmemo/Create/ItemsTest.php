<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Test\Unit\Block\Adminhtml\Order\Creditmemo\Create;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ItemsTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Sales\Block\Adminhtml\Order\Creditmemo\Create\Items */
    protected $items;

    /** @var ObjectManagerHelper */
    protected $objectManagerHelper;

    /** @var \Magento\Backend\Block\Template\Context|\PHPUnit\Framework\MockObject\MockObject */
    protected $contextMock;

    /** @var \Magento\CatalogInventory\Api\Data\StockItemInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $stockItemMock;

    /** @var \Magento\Framework\Registry|\PHPUnit\Framework\MockObject\MockObject */
    protected $registryMock;

    /** @var \Magento\Framework\App\Config\ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $scopeConfig;

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    protected $stockConfiguration;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $stockRegistry;

    protected function setUp(): void
    {
        $this->contextMock = $this->createMock(\Magento\Backend\Block\Template\Context::class);
        $this->stockRegistry = $this->getMockBuilder(\Magento\CatalogInventory\Model\StockRegistry::class)
            ->disableOriginalConstructor()
            ->setMethods(['getStockItem', '__wakeup'])
            ->getMock();

        $this->stockItemMock = $this->createPartialMock(
            \Magento\CatalogInventory\Model\Stock\Item::class,
            ['getManageStock', '__wakeup']
        );

        $this->stockConfiguration = $this->createPartialMock(
            \Magento\CatalogInventory\Model\Configuration::class,
            ['__wakeup', 'canSubtractQty']
        );

        $this->stockRegistry->expects($this->any())
            ->method('getStockItem')
            ->willReturn($this->stockItemMock);

        $this->registryMock = $this->createMock(\Magento\Framework\Registry::class);
        $this->scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $this->contextMock->expects($this->once())
            ->method('getScopeConfig')
            ->willReturn($this->scopeConfig);

        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->items = $this->objectManagerHelper->getObject(
            \Magento\Sales\Block\Adminhtml\Order\Creditmemo\Create\Items::class,
            [
                'context' => $this->contextMock,
                'stockRegistry' => $this->stockRegistry,
                'stockConfiguration' => $this->stockConfiguration,
                'registry' => $this->registryMock
            ]
        );
    }

    /**
     * @param bool $canReturnToStock
     * @param bool $manageStock
     * @param bool $result
     * @dataProvider canReturnItemsToStockDataProvider
     */
    public function testCanReturnItemsToStock($canReturnToStock, $manageStock, $result)
    {
        $productId = 7;
        $property = new \ReflectionProperty($this->items, '_canReturnToStock');
        $property->setAccessible(true);
        $this->assertNull($property->getValue($this->items));
        $this->stockConfiguration->expects($this->once())
            ->method('canSubtractQty')
            ->willReturn($canReturnToStock);

        if ($canReturnToStock) {
            $orderItem = $this->createPartialMock(
                \Magento\Sales\Model\Order\Item::class,
                ['getProductId', '__wakeup', 'getStore']
            );
            $store = $this->createPartialMock(\Magento\Store\Model\Store::class, ['getWebsiteId']);
            $store->expects($this->once())
                ->method('getWebsiteId')
                ->willReturn(10);
            $orderItem->expects($this->any())
                ->method('getStore')
                ->willReturn($store);
            $orderItem->expects($this->once())
                ->method('getProductId')
                ->willReturn($productId);

            $creditMemoItem = $this->createPartialMock(
                \Magento\Sales\Model\Order\Creditmemo\Item::class,
                ['setCanReturnToStock', 'getOrderItem', '__wakeup']
            );

            $creditMemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
            $creditMemo->expects($this->once())
                ->method('getAllItems')
                ->willReturn([$creditMemoItem]);
            $creditMemoItem->expects($this->any())
                ->method('getOrderItem')
                ->willReturn($orderItem);

            $this->stockItemMock->expects($this->once())
                ->method('getManageStock')
                ->willReturn($manageStock);

            $creditMemoItem->expects($this->once())
                ->method('setCanReturnToStock')
                ->with($this->equalTo($manageStock))
                ->willReturnSelf();

            $order = $this->createPartialMock(\Magento\Sales\Model\Order::class, ['setCanReturnToStock', '__wakeup']);
            $order->expects($this->once())
                ->method('setCanReturnToStock')
                ->with($this->equalTo($manageStock))
                ->willReturnSelf();
            $creditMemo->expects($this->once())
                ->method('getOrder')
                ->willReturn($order);

            $this->registryMock->expects($this->any())
                ->method('registry')
                ->with('current_creditmemo')
                ->willReturn($creditMemo);
        }

        $this->assertSame($result, $this->items->canReturnItemsToStock());
        $this->assertSame($result, $property->getValue($this->items));
        // lazy load test
        $this->assertSame($result, $this->items->canReturnItemsToStock());
    }

    /**
     * @return array
     */
    public function canReturnItemsToStockDataProvider()
    {
        return [
            'cannot subtract by config' => [false, true, false],
            'manage stock is enabled' => [true, true, true],
            'manage stock is disabled' => [true, false, false],
        ];
    }
}
