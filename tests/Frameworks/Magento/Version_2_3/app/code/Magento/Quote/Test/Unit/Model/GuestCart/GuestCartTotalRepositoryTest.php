<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Quote\Test\Unit\Model\GuestCart;

class GuestCartTotalRepositoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Quote\Model\GuestCart\GuestCartTotalRepository
     */
    protected $model;

    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManager;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $cartTotalRepository;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteIdMaskFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteIdMaskMock;

    /**
     * @var string
     */
    protected $maskedCartId;

    /**
     * @var int
     */
    protected $cartId;

    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->cartTotalRepository = $this->getMockBuilder(\Magento\Quote\Api\CartTotalRepositoryInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->maskedCartId = 'f216207248d65c789b17be8545e0aa73';
        $this->cartId = 123;

        $guestCartTestHelper = new GuestCartTestHelper($this);
        list($this->quoteIdMaskFactoryMock, $this->quoteIdMaskMock) = $guestCartTestHelper->mockQuoteIdMask(
            $this->maskedCartId,
            $this->cartId
        );

        $this->model = $this->objectManager->getObject(
            \Magento\Quote\Model\GuestCart\GuestCartTotalRepository::class,
            [
                'cartTotalRepository' => $this->cartTotalRepository,
                'quoteIdMaskFactory' => $this->quoteIdMaskFactoryMock,
            ]
        );
    }

    public function testGetTotals()
    {
        $retValue = 'retValue';

        $this->cartTotalRepository->expects($this->once())
            ->method('get')
            ->with($this->cartId)
            ->willReturn($retValue);
        $this->assertSame($retValue, $this->model->get($this->maskedCartId));
    }
}
