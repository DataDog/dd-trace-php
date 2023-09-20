<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\GiftMessage\Model;

class OrderRepositoryTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Framework\ObjectManagerInterface */
    protected $objectManager;

    /** @var \Magento\GiftMessage\Model\Message */
    protected $message;

    /** @var \Magento\GiftMessage\Model\OrderRepository */
    protected $giftMessageOrderRepository;

    protected function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();

        $this->message = $this->objectManager->create(\Magento\GiftMessage\Model\Message::class);
        $this->message->setSender('Romeo');
        $this->message->setRecipient('Mercutio');
        $this->message->setMessage('I thought all for the best.');

        $this->giftMessageOrderRepository = $this->objectManager->create(
            \Magento\GiftMessage\Model\OrderRepository::class
        );
    }

    protected function tearDown(): void
    {
        $this->objectManager = null;
        $this->message = null;
        $this->giftMessageOrderRepository = null;
    }

    /**
     * @magentoDataFixture Magento/GiftMessage/_files/order_with_message.php
     * @magentoConfigFixture default_store sales/gift_options/allow_order 1
     */
    public function testGet()
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $this->objectManager->create(\Magento\Sales\Model\Order::class)->loadByIncrementId('100000001');

        /** @var \Magento\GiftMessage\Api\Data\MessageInterface $message */
        $message = $this->giftMessageOrderRepository->get($order->getEntityId());
        $this->assertEquals('Romeo', $message->getSender());
        $this->assertEquals('Mercutio', $message->getRecipient());
        $this->assertEquals('I thought all for the best.', $message->getMessage());
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture default_store sales/gift_options/allow_order 1
     */
    public function testSave()
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $this->objectManager->create(\Magento\Sales\Model\Order::class)->loadByIncrementId('100000001');

        /** @var \Magento\GiftMessage\Api\Data\MessageInterface $message */
        $result = $this->giftMessageOrderRepository->save($order->getEntityId(), $this->message);

        $message = $this->giftMessageOrderRepository->get($order->getEntityId());

        $this->assertTrue($result);
        $this->assertEquals('Romeo', $message->getSender());
        $this->assertEquals('Mercutio', $message->getRecipient());
        $this->assertEquals('I thought all for the best.', $message->getMessage());
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture default_store sales/gift_options/allow_order 0
     *
     */
    public function testSaveMessageIsNotAvailable()
    {
        $this->expectExceptionMessage("The gift message isn't available.");
        $this->expectException(\Magento\Framework\Exception\CouldNotSaveException::class);
        /** @var \Magento\Sales\Model\Order $order */
        $order = $this->objectManager->create(\Magento\Sales\Model\Order::class)->loadByIncrementId('100000001');

        /** @var \Magento\GiftMessage\Api\Data\MessageInterface $message */
        $this->giftMessageOrderRepository->save($order->getEntityId(), $this->message);
    }

    /**
     * @magentoDataFixture Magento/GiftMessage/_files/virtual_order.php
     * @magentoConfigFixture default_store sales/gift_options/allow_order 1
     *
     */
    public function testSaveMessageIsVirtual()
    {
        $this->expectExceptionMessage("Gift messages can't be used for virtual products.");
        $this->expectException(\Magento\Framework\Exception\State\InvalidTransitionException::class);
        /** @var \Magento\Sales\Model\Order $order */
        $order = $this->objectManager->create(\Magento\Sales\Model\Order::class)->loadByIncrementId('100000001');

        /** @var \Magento\GiftMessage\Api\Data\MessageInterface $message */
        $this->giftMessageOrderRepository->save($order->getEntityId(), $this->message);
    }

    /**
     * @magentoDataFixture Magento/GiftMessage/_files/empty_order.php
     * @magentoConfigFixture default_store sales/gift_options/allow_order 1
     *
     */
    public function testSaveMessageIsEmpty()
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);
        /** @var \Magento\Sales\Model\Order $order */
        $order = $this->objectManager->create(\Magento\Sales\Model\Order::class)->loadByIncrementId('100000001');

        /** @var \Magento\GiftMessage\Api\Data\MessageInterface $message */
        $this->giftMessageOrderRepository->save($order->getEntityId(), $this->message);

        $this->expectExceptionMessage(
            "Gift messages can't be used for an empty order. Create an order, add an item, and try again."
        );
    }

    /**
     * @magentoDataFixture Magento/GiftMessage/_files/empty_order.php
     * @magentoConfigFixture default_store sales/gift_options/allow_order 1
     *
     */
    public function testSaveMessageNoProvidedItemId()
    {
        $this->expectExceptionMessage("No order exists with this ID. Verify your information and try again.");
        $this->expectException(\Magento\Framework\Exception\NoSuchEntityException::class);
        /** @var \Magento\Sales\Model\Order $order */
        $order = $this->objectManager->create(\Magento\Sales\Model\Order::class)->loadByIncrementId('100000001');

        /** @var \Magento\GiftMessage\Api\Data\MessageInterface $message */
        $this->giftMessageOrderRepository->save($order->getEntityId() * 10, $this->message);
    }
}
