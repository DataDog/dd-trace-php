<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Sales\Service\V1;

use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\TestFramework\TestCase\WebapiAbstract;

/**
 * Class OrderCommentAddTest
 *
 * @package Magento\Sales\Service\V1
 */
class OrderStatusHistoryAddTest extends WebapiAbstract
{
    const SERVICE_READ_NAME = 'salesOrderManagementV1';

    const SERVICE_VERSION = 'V1';

    const ORDER_INCREMENT_ID = '100000001';

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
    }

    /**
     * @magentoApiDataFixture Magento/Sales/_files/order.php
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function testOrderCommentAdd()
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $this->objectManager->create(\Magento\Sales\Model\Order::class);
        $order->loadByIncrementId(self::ORDER_INCREMENT_ID);

        $commentData = [
            OrderStatusHistoryInterface::COMMENT => 'Hello',
            OrderStatusHistoryInterface::ENTITY_ID => null,
            OrderStatusHistoryInterface::IS_CUSTOMER_NOTIFIED => 1,
            OrderStatusHistoryInterface::CREATED_AT => null,
            OrderStatusHistoryInterface::PARENT_ID => $order->getId(),
            OrderStatusHistoryInterface::ENTITY_NAME => null,
            OrderStatusHistoryInterface::STATUS => $order->getStatus(),
            OrderStatusHistoryInterface::IS_VISIBLE_ON_FRONT => 1,
        ];

        $requestData = ['id' => $order->getId(), 'statusHistory' => $commentData];
        $serviceInfo = [
            'rest' => [
                'resourcePath' => '/V1/orders/' . $order->getId() . '/comments',
                'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_POST,
            ],
            'soap' => [
                'service' => self::SERVICE_READ_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_READ_NAME . 'addComment',
            ],
        ];

        $this->_webApiCall($serviceInfo, $requestData);

        //Verification
        $comments = $order->load($order->getId())->getAllStatusHistory();
        $comment = reset($comments);

        $this->assertEquals(
            $commentData[OrderStatusHistoryInterface::COMMENT],
            $comment->getComment()
        );
        $this->assertEquals(
            $commentData[OrderStatusHistoryInterface::PARENT_ID],
            $comment->getParentId()
        );
        $this->assertEquals(
            $commentData[OrderStatusHistoryInterface::IS_CUSTOMER_NOTIFIED],
            $comment->getIsCustomerNotified()
        );
        $this->assertEquals(
            $commentData[OrderStatusHistoryInterface::IS_VISIBLE_ON_FRONT],
            $comment->getIsVisibleOnFront()
        );
        $this->assertEquals(
            $commentData[OrderStatusHistoryInterface::STATUS],
            $comment->getStatus()
        );
    }
}
