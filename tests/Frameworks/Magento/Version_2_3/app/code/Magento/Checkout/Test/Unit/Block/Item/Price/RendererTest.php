<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Checkout\Test\Unit\Block\Item\Price;

use \Magento\Checkout\Block\Item\Price\Renderer;

class RendererTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Renderer
     */
    protected $renderer;

    protected function setUp(): void
    {
        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->renderer = $objectManagerHelper->getObject(
            \Magento\Checkout\Block\Item\Price\Renderer::class
        );
    }

    public function testSetItem()
    {
        $item = $this->getMockBuilder(\Magento\Quote\Model\Quote\Item\AbstractItem::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->renderer->setItem($item);
        $this->assertEquals($item, $this->renderer->getItem());
    }
}
