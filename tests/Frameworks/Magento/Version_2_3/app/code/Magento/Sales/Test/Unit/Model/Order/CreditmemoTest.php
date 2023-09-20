<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Sales\Test\Unit\Model\Order;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\OrderFactory;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\Item\CollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\Item\Collection as ItemCollection;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Class CreditmemoTest
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CreditmemoTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var OrderRepositoryInterface |\PHPUnit\Framework\MockObject\MockObject
     */
    protected $orderRepository;

    /**
     * @var \Magento\Sales\Model\Order\Creditmemo
     */
    protected $creditmemo;

    /**
     * @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $scopeConfigMock;

    /**
     * @var CollectionFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $cmItemCollectionFactoryMock;

    protected function setUp(): void
    {
        $this->orderRepository = $this->getMockForAbstractClass(OrderRepositoryInterface::class);
        $this->scopeConfigMock = $this->getMockForAbstractClass(ScopeConfigInterface::class);

        $objectManagerHelper = new ObjectManagerHelper($this);
        $this->cmItemCollectionFactoryMock = $this->getMockBuilder(
            \Magento\Sales\Model\ResourceModel\Order\Creditmemo\Item\CollectionFactory::class
        )->disableOriginalConstructor()
        ->setMethods(['create'])
        ->getMock();

        $arguments = [
            'context' => $this->createMock(\Magento\Framework\Model\Context::class),
            'registry' => $this->createMock(\Magento\Framework\Registry::class),
            'localeDate' => $this->createMock(
                \Magento\Framework\Stdlib\DateTime\TimezoneInterface::class
            ),
            'dateTime' => $this->createMock(\Magento\Framework\Stdlib\DateTime::class),
            'creditmemoConfig' => $this->createMock(
                \Magento\Sales\Model\Order\Creditmemo\Config::class
            ),
            'cmItemCollectionFactory' => $this->cmItemCollectionFactoryMock,
            'calculatorFactory' => $this->createMock(\Magento\Framework\Math\CalculatorFactory::class),
            'storeManager' => $this->createMock(\Magento\Store\Model\StoreManagerInterface::class),
            'commentFactory' => $this->createMock(\Magento\Sales\Model\Order\Creditmemo\CommentFactory::class),
            'commentCollectionFactory' => $this->createMock(
                \Magento\Sales\Model\ResourceModel\Order\Creditmemo\Comment\CollectionFactory::class
            ),
            'scopeConfig' => $this->scopeConfigMock,
            'orderRepository' => $this->orderRepository,
        ];
        $this->creditmemo = $objectManagerHelper->getObject(
            \Magento\Sales\Model\Order\Creditmemo::class,
            $arguments
        );
    }

    public function testGetOrder()
    {
        $orderId = 100000041;
        $this->creditmemo->setOrderId($orderId);
        $entityName = 'creditmemo';
        $order = $this->createPartialMock(
            \Magento\Sales\Model\Order::class,
            ['load', 'setHistoryEntityName', '__wakeUp']
        );
        $this->creditmemo->setOrderId($orderId);
        $order->expects($this->atLeastOnce())
            ->method('setHistoryEntityName')
            ->with($entityName)
            ->willReturnSelf();
        $this->orderRepository->expects($this->atLeastOnce())
            ->method('get')
            ->with($orderId)
            ->willReturn($order);

        $this->assertEquals($order, $this->creditmemo->getOrder());
    }

    public function testGetEntityType()
    {
        $this->assertEquals('creditmemo', $this->creditmemo->getEntityType());
    }

    public function testIsValidGrandTotalGrandTotalEmpty()
    {
        $this->creditmemo->setGrandTotal(0);
        $this->assertFalse($this->creditmemo->isValidGrandTotal());
    }

    public function testIsValidGrandTotalGrandTotal()
    {
        $this->creditmemo->setGrandTotal(0);
        $this->assertFalse($this->creditmemo->isValidGrandTotal());
    }

    public function testIsValidGrandTotal()
    {
        $this->creditmemo->setGrandTotal(1);
        $this->assertTrue($this->creditmemo->isValidGrandTotal());
    }

    public function testGetIncrementId()
    {
        $this->creditmemo->setIncrementId('test_increment_id');
        $this->assertEquals('test_increment_id', $this->creditmemo->getIncrementId());
    }

    public function testGetItemsCollectionWithId()
    {
        $id = 1;
        $this->creditmemo->setId($id);

        $items = [];
        $itemMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Creditmemo\Item::class)
            ->disableOriginalConstructor()
            ->getMock();
        $itemMock->expects($this->once())
            ->method('setCreditmemo')
            ->with($this->creditmemo);
        $items[] = $itemMock;

        /** @var ItemCollection|\PHPUnit\Framework\MockObject\MockObject $itemCollectionMock */
        $itemCollectionMock = $this->getMockBuilder(
            \Magento\Sales\Model\ResourceModel\Order\Creditmemo\Item\Collection::class
        )
            ->disableOriginalConstructor()
            ->getMock();
        $itemCollectionMock->expects($this->once())
            ->method('setCreditmemoFilter')
            ->with($id)
            ->willReturn($items);

        $this->cmItemCollectionFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($itemCollectionMock);

        $itemsCollection = $this->creditmemo->getItemsCollection();
        $this->assertEquals($items, $itemsCollection);
    }

    public function testGetItemsCollectionWithoutId()
    {
        $items = [];
        $itemMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Creditmemo\Item::class)
            ->disableOriginalConstructor()
            ->getMock();
        $itemMock->expects($this->never())
            ->method('setCreditmemo');
        $items[] = $itemMock;

        /** @var ItemCollection|\PHPUnit\Framework\MockObject\MockObject $itemCollectionMock */
        $itemCollectionMock = $this->getMockBuilder(
            \Magento\Sales\Model\ResourceModel\Order\Creditmemo\Item\Collection::class
        )
            ->disableOriginalConstructor()
            ->getMock();
        $itemCollectionMock->expects($this->once())
            ->method('setCreditmemoFilter')
            ->with(null)
            ->willReturn($items);

        $this->cmItemCollectionFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($itemCollectionMock);

        $itemsCollection = $this->creditmemo->getItemsCollection();
        $this->assertEquals($items, $itemsCollection);
    }
}
