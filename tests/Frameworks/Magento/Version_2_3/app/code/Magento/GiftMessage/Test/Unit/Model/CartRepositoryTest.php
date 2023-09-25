<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\GiftMessage\Test\Unit\Model;

use Magento\GiftMessage\Model\CartRepository;

class CartRepositoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var CartRepository
     */
    protected $cartRepository;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteRepositoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $messageFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $messageMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteItemMock;

    /**
     * @var string
     */
    protected $cartId = 13;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $storeManagerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $giftMessageManagerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $helperMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $storeMock;

    protected function setUp(): void
    {
        $this->quoteRepositoryMock = $this->createMock(\Magento\Quote\Api\CartRepositoryInterface::class);
        $this->messageFactoryMock = $this->createPartialMock(
            \Magento\GiftMessage\Model\MessageFactory::class,
            [
                'create',
                '__wakeup'
            ]
        );
        $this->messageMock = $this->createMock(\Magento\GiftMessage\Model\Message::class);
        $this->quoteItemMock = $this->createPartialMock(
            \Magento\Quote\Model\Quote\Item::class,
            [
                'getGiftMessageId',
                '__wakeup'
            ]
        );
        $this->quoteMock = $this->createPartialMock(
            \Magento\Quote\Model\Quote::class,
            [
                'getGiftMessageId',
                'getItemById',
                'getItemsCount',
                'isVirtual',
                '__wakeup',
            ]
        );
        $this->storeManagerMock = $this->createMock(\Magento\Store\Model\StoreManagerInterface::class);
        $this->giftMessageManagerMock =
            $this->createMock(\Magento\GiftMessage\Model\GiftMessageManager::class);
        $this->helperMock = $this->createMock(\Magento\GiftMessage\Helper\Message::class);
        $this->storeMock = $this->createMock(\Magento\Store\Model\Store::class);
        $this->cartRepository = new \Magento\GiftMessage\Model\CartRepository(
            $this->quoteRepositoryMock,
            $this->storeManagerMock,
            $this->giftMessageManagerMock,
            $this->helperMock,
            $this->messageFactoryMock
        );

        $this->quoteRepositoryMock->expects($this->once())
            ->method('getActive')
            ->with($this->cartId)
            ->willReturn($this->quoteMock);
    }

    public function testGetWithOutMessageId()
    {
        $messageId = 0;
        $this->quoteMock->expects($this->once())->method('getGiftMessageId')->willReturn($messageId);
        $this->assertNull($this->cartRepository->get($this->cartId));
    }

    public function testGet()
    {
        $messageId = 156;

        $this->quoteMock->expects($this->once())->method('getGiftMessageId')->willReturn($messageId);
        $this->messageFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->messageMock);
        $this->messageMock->expects($this->once())->method('load')->willReturn($this->messageMock);

        $this->assertEquals($this->messageMock, $this->cartRepository->get($this->cartId));
    }

    /**
     */
    public function testSaveWithInputException()
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);
        $this->expectExceptionMessage('Gift messages can\'t be used for an empty cart. Add an item and try again.');

        $this->quoteMock->expects($this->once())->method('getItemsCount')->willReturn(0);
        $this->cartRepository->save($this->cartId, $this->messageMock);
    }

    /**
     */
    public function testSaveWithInvalidTransitionException()
    {
        $this->expectException(\Magento\Framework\Exception\State\InvalidTransitionException::class);
        $this->expectExceptionMessage('Gift messages can\'t be used for virtual products.');

        $this->quoteMock->expects($this->once())->method('getItemsCount')->willReturn(1);
        $this->quoteMock->expects($this->once())->method('isVirtual')->willReturn(true);
        $this->cartRepository->save($this->cartId, $this->messageMock);
    }

    public function testSave()
    {
        $this->quoteMock->expects($this->once())->method('isVirtual')->willReturn(false);
        $this->quoteMock->expects($this->once())->method('getItemsCount')->willReturn(1);
        $this->storeManagerMock->expects($this->once())->method('getStore')->willReturn($this->storeMock);
        $this->helperMock->expects($this->once())
            ->method('isMessagesAllowed')
            ->with('quote', $this->quoteMock, $this->storeMock)
            ->willReturn(true);
        $this->giftMessageManagerMock->expects($this->once())
            ->method('setMessage')
            ->with($this->quoteMock, 'quote', $this->messageMock)
            ->willReturn($this->giftMessageManagerMock);
        $this->messageMock->expects($this->once())->method('getMessage')->willReturn('message');

        $this->assertTrue($this->cartRepository->save($this->cartId, $this->messageMock));
    }
}
