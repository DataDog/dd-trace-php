<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\GroupedProduct\Test\Unit\Model\Sales\AdminOrder\Product\Quote\Plugin;

use Magento\GroupedProduct\Model\Sales\AdminOrder\Product\Quote\Plugin\Initializer as QuoteInitializerPlugin;
use Magento\Sales\Model\AdminOrder\Product\Quote\Initializer as QuoteInitializer;
use Magento\Quote\Model\Quote;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Framework\DataObject;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

class InitializerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ObjectManagerHelper
     */
    private $objectManagerHelper;

    /**
     * @var QuoteInitializerPlugin|\PHPUnit\Framework\MockObject\MockObject
     */
    private $plugin;

    /**
     * @var QuoteInitializer|\PHPUnit\Framework\MockObject\MockObject
     */
    private $initializer;

    /**
     * @var Quote|\PHPUnit\Framework\MockObject\MockObject
     */
    private $quote;

    /**
     * @var QuoteItem|\PHPUnit\Framework\MockObject\MockObject
     */
    private $quoteItem;

    /**
     * @var Product|\PHPUnit\Framework\MockObject\MockObject
     */
    private $product;

    /**
     * @var DataObject|\PHPUnit\Framework\MockObject\MockObject
     */
    private $config;

    protected function setUp(): void
    {
        $this->objectManagerHelper = new ObjectManagerHelper($this);

        $this->initializer = $this->getMockBuilder(QuoteInitializer::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->quote = $this->getMockBuilder(Quote::class)
            ->setMethods(['addProduct'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->setMethods(['getTypeId'])
            ->getMock();
        $this->quoteItem = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->config = $this->getMockBuilder(DataObject::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->plugin = $this->objectManagerHelper->getObject(
            QuoteInitializerPlugin::class
        );
    }

    public function testAfterInit()
    {
        $this->assertSame(
            $this->quoteItem,
            $this->plugin->afterInit($this->initializer, $this->quoteItem, $this->quote, $this->product, $this->config)
        );
    }
}
