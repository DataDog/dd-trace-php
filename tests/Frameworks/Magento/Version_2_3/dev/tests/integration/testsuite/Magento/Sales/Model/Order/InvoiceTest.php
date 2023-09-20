<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Model\Order;

class InvoiceTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    private $_collection;

    protected function setUp(): void
    {
        $this->_collection = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Sales\Model\ResourceModel\Order\Collection::class
        );
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testOrderTotalItemCount()
    {
        $expectedResult = [['total_item_count' => 1]];
        $actualResult = [];
        /** @var \Magento\Sales\Model\Order $order */
        foreach ($this->_collection->getItems() as $order) {
            $actualResult[] = ['total_item_count' => $order->getData('total_item_count')];
        }
        $this->assertEquals($expectedResult, $actualResult);
    }
}
