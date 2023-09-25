<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Controller\Adminhtml\Order;

/**
 * @magentoAppArea adminhtml
 */
class CreditmemoTest extends \Magento\TestFramework\TestCase\AbstractBackendController
{
    /**
     * @magentoConfigFixture current_store cataloginventory/item_options/auto_return 1
     * @magentoDataFixture Magento/Sales/_files/order_info.php
     */
    public function testAddCommentAction()
    {
        $this->markTestIncomplete('https://github.com/magento-engcom/msi/issues/393');
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        /** @var \Magento\CatalogInventory\Api\StockIndexInterface $stockIndex */
        $stockIndex = $objectManager->get(\Magento\CatalogInventory\Api\StockIndexInterface::class);
        $stockIndex->rebuild(1, 1);

        /** @var \Magento\CatalogInventory\Api\StockStateInterface $stockState */
        $stockState = $objectManager->create(\Magento\CatalogInventory\Api\StockStateInterface::class);
        $this->assertEquals(95, $stockState->getStockQty(1, 1));

        /** @var \Magento\Sales\Model\Order $order */
        $order = $objectManager->create(\Magento\Sales\Model\Order::class);
        $order->load('100000001', 'increment_id');
        $items = $order->getCreditmemosCollection()->getItems();
        $creditmemo = array_shift($items);
        $comment = 'Test Comment 02';
        $this->getRequest()->setParam('creditmemo_id', $creditmemo->getId());
        $this->getRequest()->setPostValue('comment', ['comment' => $comment]);
        $this->dispatch('backend/sales/order_creditmemo/addComment/id/' . $creditmemo->getId());
        $html = $this->getResponse()->getBody();
        $this->assertStringContainsString($comment, $html);

        /** @var \Magento\CatalogInventory\Api\StockStateInterface $stockState */
        $stockState = $objectManager->create(\Magento\CatalogInventory\Api\StockStateInterface::class);
        $this->assertEquals(95, $stockState->getStockQty(1, 1));
    }
}
