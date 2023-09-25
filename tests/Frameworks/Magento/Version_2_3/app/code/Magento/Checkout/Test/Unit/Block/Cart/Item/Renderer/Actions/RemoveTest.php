<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Checkout\Test\Unit\Block\Cart\Item\Renderer\Actions;

use Magento\Checkout\Block\Cart\Item\Renderer\Actions\Remove;
use Magento\Checkout\Helper\Cart;
use Magento\Quote\Model\Quote\Item;

class RemoveTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Remove
     */
    protected $model;

    /** @var Cart|\PHPUnit\Framework\MockObject\MockObject */
    protected $cartHelperMock;

    protected function setUp(): void
    {
        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->cartHelperMock = $this->getMockBuilder(\Magento\Checkout\Helper\Cart::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->model = $objectManagerHelper->getObject(
            \Magento\Checkout\Block\Cart\Item\Renderer\Actions\Remove::class,
            [
                'cartHelper' => $this->cartHelperMock,
            ]
        );
    }

    public function testGetConfigureUrl()
    {
        $json = '{json;}';

        /**
         * @var Item|\PHPUnit\Framework\MockObject\MockObject $itemMock
         */
        $itemMock = $this->getMockBuilder(\Magento\Quote\Model\Quote\Item::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->cartHelperMock->expects($this->once())
            ->method('getDeletePostJson')
            ->with($itemMock)
            ->willReturn($json);

        $this->model->setItem($itemMock);
        $this->assertEquals($json, $this->model->getDeletePostJson());
    }
}
