<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Model\Order\Email\Sender;

use Magento\TestFramework\Helper\Bootstrap;

class CreditmemoSenderTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testSend()
    {
        Bootstrap::getInstance()
            ->loadArea(\Magento\Framework\App\Area::AREA_FRONTEND);
        $order = Bootstrap::getObjectManager()
            ->create(\Magento\Sales\Model\Order::class);
        $order->loadByIncrementId('100000001');
        $order->setCustomerEmail('customer@example.com');

        $creditmemo = Bootstrap::getObjectManager()->create(
            \Magento\Sales\Model\Order\Creditmemo::class
        );
        $creditmemo->setOrder($order);

        $this->assertEmpty($creditmemo->getEmailSent());

        $creditmemoSender = Bootstrap::getObjectManager()
            ->create(\Magento\Sales\Model\Order\Email\Sender\CreditmemoSender::class);
        $result = $creditmemoSender->send($creditmemo, true);

        $this->assertTrue($result);
        $this->assertNotEmpty($creditmemo->getEmailSent());
    }
}
