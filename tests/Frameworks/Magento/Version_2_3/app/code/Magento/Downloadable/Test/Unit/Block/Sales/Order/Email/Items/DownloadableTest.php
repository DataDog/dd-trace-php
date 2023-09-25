<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Downloadable\Test\Unit\Block\Sales\Order\Email\Items;

use Magento\Downloadable\Model\ResourceModel\Link\Purchased\Item\CollectionFactory;

/**
 * Tests Magento\Downloadable\Test\Unit\Block\Sales\Order\Email\Items\Downloadable
 */
class DownloadableTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Downloadable\Block\Sales\Order\Email\Items\Downloadable
     */
    protected $block;

    /**
     * @var \Magento\Downloadable\Model\Link\PurchasedFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $purchasedFactory;

    /**
     * @var CollectionFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $itemsFactory;

    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $contextMock = $this->getMockBuilder(\Magento\Backend\Block\Template\Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->purchasedFactory = $this->getMockBuilder(\Magento\Downloadable\Model\Link\PurchasedFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->itemsFactory = $this->getMockBuilder(
            \Magento\Downloadable\Model\ResourceModel\Link\Purchased\Item\CollectionFactory::class
        )
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $this->block = $objectManager->getObject(
            \Magento\Downloadable\Block\Sales\Order\Email\Items\Downloadable::class,
            [
                'context' => $contextMock,
                'purchasedFactory' => $this->purchasedFactory,
                'itemsFactory' => $this->itemsFactory
            ]
        );
    }

    public function testGetLinks()
    {
        $item = $this->getMockBuilder(\Magento\Sales\Model\Order\Item::class)
            ->disableOriginalConstructor()
            ->setMethods(['getOrderItemId'])
            ->getMock();
        $linkPurchased = $this->getMockBuilder(\Magento\Downloadable\Model\Link\Purchased::class)
            ->disableOriginalConstructor()
            ->setMethods(['load'])
            ->getMock();
        $itemCollection =
            $this->getMockBuilder(\Magento\Downloadable\Model\ResourceModel\Link\Purchased\Item\Collection::class)
            ->disableOriginalConstructor()
            ->setMethods(['addFieldToFilter'])
            ->getMock();

        $this->block->setData('item', $item);
        $this->purchasedFactory->expects($this->once())->method('create')->willReturn($linkPurchased);
        $linkPurchased->expects($this->once())->method('load')->with('orderItemId', 'order_item_id')->willReturnSelf();
        $item->expects($this->any())->method('getOrderItemId')->willReturn('orderItemId');
        $this->itemsFactory->expects($this->once())->method('create')->willReturn($itemCollection);
        $itemCollection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('order_item_id', 'orderItemId')
            ->willReturnSelf();

        $this->assertEquals($linkPurchased, $this->block->getLinks());
    }
}
