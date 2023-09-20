<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Checkout\Test\Unit\Controller\Cart;

use Magento\Framework\Controller\ResultFactory;

/**
 * Shopping cart edit tests
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ConfigureTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectManagerMock;

    /**
     * @var \Magento\Framework\Controller\ResultFactory | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultFactoryMock;

    /**
     * @var \Magento\Framework\Controller\Result\Redirect | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultRedirectMock;

    /**
     * @var \Magento\Framework\App\ResponseInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $responseMock;

    /**
     * @var \Magento\Framework\App\RequestInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $requestMock;

    /**
     * @var \Magento\Framework\Message\ManagerInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $messageManagerMock;

    /**
     * @var \Magento\Checkout\Controller\Cart\Configure | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $configureController;

    /**
     * @var \Magento\Framework\App\Action\Context | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $contextMock;

    /**
     * @var \Magento\Checkout\Model\Cart | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $cartMock;

    protected function setUp(): void
    {
        $this->contextMock = $this->createMock(\Magento\Framework\App\Action\Context::class);
        $this->objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $this->responseMock = $this->createMock(\Magento\Framework\App\ResponseInterface::class);
        $this->requestMock = $this->createMock(\Magento\Framework\App\RequestInterface::class);
        $this->messageManagerMock = $this->createMock(\Magento\Framework\Message\ManagerInterface::class);
        $this->cartMock = $this->getMockBuilder(\Magento\Checkout\Model\Cart::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resultFactoryMock = $this->getMockBuilder(\Magento\Framework\Controller\ResultFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resultRedirectMock = $this->getMockBuilder(\Magento\Framework\Controller\Result\Redirect::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->contextMock->expects($this->once())
            ->method('getResultFactory')
            ->willReturn($this->resultFactoryMock);
        $this->contextMock->expects($this->once())
            ->method('getRequest')
            ->willReturn($this->requestMock);
        $this->contextMock->expects($this->once())
            ->method('getObjectManager')
            ->willReturn($this->objectManagerMock);
        $this->contextMock->expects($this->once())
            ->method('getMessageManager')
            ->willReturn($this->messageManagerMock);

        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->configureController = $objectManagerHelper->getObject(
            \Magento\Checkout\Controller\Cart\Configure::class,
            [
                'context' => $this->contextMock,
                'cart' => $this->cartMock
            ]
        );
    }

    /**
     * Test checks controller call product view and send parameter to it
     *
     * @return void
     */
    public function testPrepareAndRenderCall()
    {
        $quoteId = 1;
        $actualProductId = 1;
        $quoteMock = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)
            ->disableOriginalConstructor()
            ->getMock();
        $quoteItemMock = $this->getMockBuilder(\Magento\Quote\Model\Quote\Item::class)
            ->disableOriginalConstructor()
            ->getMock();
        $productMock = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->disableOriginalConstructor()
            ->getMock();
        $viewMock = $this->getMockBuilder(\Magento\Catalog\Helper\Product\View::class)
            ->disableOriginalConstructor()
            ->getMock();
        $pageMock = $this->getMockBuilder(\Magento\Framework\View\Result\Page::class)
            ->disableOriginalConstructor()
            ->getMock();
        $buyRequestMock = $this->getMockBuilder(\Magento\Framework\DataObject::class)
            ->disableOriginalConstructor()
            ->getMock();
        //expects
        $this->requestMock->expects($this->at(0))
            ->method('getParam')
            ->with('id')
            ->willReturn($quoteId);
        $this->requestMock->expects($this->at(1))
            ->method('getParam')
            ->with('product_id')
            ->willReturn($actualProductId);
        $this->cartMock->expects($this->any())->method('getQuote')->willReturn($quoteMock);

        $quoteItemMock->expects($this->exactly(1))->method('getBuyRequest')->willReturn($buyRequestMock);

        $this->resultFactoryMock->expects($this->once())
            ->method('create')
            ->with(ResultFactory::TYPE_PAGE, [])
            ->willReturn($pageMock);
        $this->objectManagerMock->expects($this->at(0))
            ->method('get')
            ->with(\Magento\Catalog\Helper\Product\View::class)
            ->willReturn($viewMock);

        $viewMock->expects($this->once())->method('prepareAndRender')->with(
            $pageMock,
            $actualProductId,
            $this->configureController,
            $this->callback(
                function ($subject) use ($buyRequestMock) {
                    return $subject->getBuyRequest() === $buyRequestMock;
                }
            )
        )->willReturn($pageMock);

        $quoteMock->expects($this->once())->method('getItemById')->willReturn($quoteItemMock);
        $quoteItemMock->expects($this->exactly(2))->method('getProduct')->willReturn($productMock);

        $productMock->expects($this->exactly(2))->method('getId')->willReturn($actualProductId);

        $this->assertSame($pageMock, $this->configureController->execute());
    }

    /**
     * Test checks controller redirect user to cart
     * if user request product id in cart edit page is not same as quota product id
     *
     * @return void
     */
    public function testRedirectWithWrongProductId()
    {
        $quotaId = 1;
        $productIdInQuota = 1;
        $productIdInRequest = null;
        $quoteItemMock = $this->getMockBuilder(\Magento\Quote\Model\Quote\Item::class)
            ->disableOriginalConstructor()
            ->getMock();
        $quoteMock = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)
            ->disableOriginalConstructor()
            ->getMock();
        $productMock = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->requestMock->expects($this->any())
            ->method('getParam')
            ->willReturnMap([
                ['id', null, $quotaId],
                ['product_id', null, $productIdInRequest]
            ]);
        $this->cartMock->expects($this->any())->method('getQuote')->willReturn($quoteMock);
        $quoteMock->expects($this->once())->method('getItemById')->willReturn($quoteItemMock);
        $quoteItemMock->expects($this->once())->method('getProduct')->willReturn($productMock);
        $productMock->expects($this->once())->method('getId')->willReturn($productIdInQuota);
        $this->messageManagerMock->expects($this->once())
            ->method('addErrorMessage')
            ->willReturn('');
        $this->resultRedirectMock->expects($this->once())
            ->method('setPath')
            ->with('checkout/cart', [])
            ->willReturnSelf();
        $this->resultFactoryMock->expects($this->once())
            ->method('create')
            ->with(ResultFactory::TYPE_REDIRECT, [])
            ->willReturn($this->resultRedirectMock);
        $this->assertSame($this->resultRedirectMock, $this->configureController->execute());
    }
}
