<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Customer\Test\Unit\Block\Adminhtml\Edit\Tab\View\Grid\Renderer;

class ItemTest extends \PHPUnit\Framework\TestCase
{
    /** @var  \PHPUnit\Framework\MockObject\MockObject */
    protected $item;

    /** @var  \Magento\Customer\Block\Adminhtml\Edit\Tab\View\Grid\Renderer\Item */
    protected $itemBlock;

    /**
     * @param $amountOption
     * @param bool $withoutOptions
     */
    public function configure($amountOption, $withoutOptions = false)
    {
        $options = [];
        for ($i = 1; $i <= $amountOption; $i++) {
            $options[] = [
                'label' => "testLabel{$i}",
                'value' => ['1 x Configurable Product 49-option 3 <span class="price">$10.00</span>']
            ];
        }

        $product = $this->createPartialMock(\Magento\Catalog\Model\Product::class, ['getTypeId', 'getName']);
        $product
            ->expects($this->once())
            ->method('getTypeId')
            ->willReturn(null);
        $product
            ->expects($this->once())
            ->method('getName')
            ->willReturn('testProductName');

        $this->item = $this->createPartialMock(\Magento\Wishlist\Model\Item::class, ['getProduct']);
        $this->item
            ->expects($this->atLeastOnce())
            ->method('getProduct')
            ->willReturn($product);

        $productConfigPool = $this->createPartialMock(
            \Magento\Catalog\Helper\Product\ConfigurationPool::class,
            ['get']
        );
        $helper = $this->createPartialMock(\Magento\Bundle\Helper\Catalog\Product\Configuration::class, ['getOptions']);
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $productConfig = $objectManager->getObject(\Magento\Catalog\Helper\Product\Configuration::class);
        $escaper = $objectManager->getObject(\Magento\Framework\Escaper::class);
        if ($withoutOptions) {
            $helper
                ->expects($this->once())
                ->method('getOptions')
                ->willReturn(null);
        } else {
            $helper
                ->expects($this->once())
                ->method('getOptions')
                ->willReturn($options);
        }

        $context = $this->createPartialMock(\Magento\Backend\Block\Context::class, ['getEscaper']);
        $context
            ->expects($this->once())
            ->method('getEscaper')
            ->willReturn($escaper);

        $productConfigPool
            ->expects($this->once())
            ->method('get')
            ->with(\Magento\Catalog\Helper\Product\Configuration::class)
            ->willReturn($helper);

        $this->itemBlock = new \Magento\Customer\Block\Adminhtml\Edit\Tab\View\Grid\Renderer\Item(
            $context,
            $productConfig,
            $productConfigPool
        );
    }

    public function testRenderWithoutOptions()
    {
        $this->configure(0, true);
        $this->itemBlock->render($this->item);
    }

    /**
     * @dataProvider optionHtmlProvider
     */
    public function testRender($amountOption, $expectedHtml)
    {
        $this->configure($amountOption);
        $realHtml = '<xhtml>' . $this->itemBlock->render($this->item) . '</xhtml>';
        $this->assertXmlStringEqualsXmlString($expectedHtml, $realHtml);
    }

    /**
     * @return array
     */
    public function optionHtmlProvider()
    {
        return [
            [
                2,
                <<<HTML
                        <xhtml>
                            <div class="product-title">testProductName</div>
                            <dl class="item-options">
                                <dt>testLabel1</dt>
                                <dd>1 x Configurable Product 49-option 3 <span class="price">$10.00</span></dd>
                                <dt>testLabel2</dt>
                                <dd>1 x Configurable Product 49-option 3 <span class="price">$10.00</span></dd>
                            </dl>
                        </xhtml>
HTML
            ],
            [
                1,
                <<<HTML
                        <xhtml>
                            <div class="product-title">testProductName</div>
                            <dl class="item-options">
                                <dt>testLabel1</dt>
                                <dd>1 x Configurable Product 49-option 3 <span class="price">$10.00</span></dd>
                            </dl>
                        </xhtml>
HTML
            ],
        ];
    }
}
