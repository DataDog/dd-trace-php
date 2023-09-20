<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Store\Test\Unit\Block;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class SwitcherTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Store\Block\Switcher */
    protected $switcher;

    /** @var \Magento\Framework\View\Element\Template\Context|\PHPUnit\Framework\MockObject\MockObject */
    protected $context;

    /** @var \Magento\Framework\Data\Helper\PostHelper|\PHPUnit\Framework\MockObject\MockObject */
    protected $corePostDataHelper;

    /** @var \Magento\Store\Model\StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $storeManager;

    /** @var \Magento\Framework\UrlInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $urlBuilder;

    /** @var \Magento\Store\Api\Data\StoreInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $store;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->storeManager = $this->getMockBuilder(\Magento\Store\Model\StoreManagerInterface::class)->getMock();
        $this->urlBuilder = $this->createMock(\Magento\Framework\UrlInterface::class);
        $this->context = $this->createMock(\Magento\Framework\View\Element\Template\Context::class);
        $this->context->expects($this->any())->method('getStoreManager')->willReturn($this->storeManager);
        $this->context->expects($this->any())->method('getUrlBuilder')->willReturn($this->urlBuilder);
        $this->corePostDataHelper = $this->createMock(\Magento\Framework\Data\Helper\PostHelper::class);
        $this->store = $this->getMockBuilder(\Magento\Store\Api\Data\StoreInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->switcher = (new ObjectManager($this))->getObject(
            \Magento\Store\Block\Switcher::class,
            [
                'context' => $this->context,
                'postDataHelper' => $this->corePostDataHelper,
            ]
        );
    }

    /**
     * @return void
     */
    public function testGetTargetStorePostData()
    {
        $store = $this->getMockBuilder(\Magento\Store\Model\Store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $store->expects($this->any())
            ->method('getCode')
            ->willReturn('new-store');
        $storeSwitchUrl = 'http://domain.com/stores/store/redirect';
        $store->expects($this->atLeastOnce())
            ->method('getCurrentUrl')
            ->with(false)
            ->willReturn($storeSwitchUrl);
        $this->storeManager->expects($this->once())
            ->method('getStore')
            ->willReturn($this->store);
        $this->store->expects($this->once())
            ->method('getCode')
            ->willReturn('old-store');
        $this->urlBuilder->expects($this->once())
            ->method('getUrl')
            ->willReturn($storeSwitchUrl);
        $this->corePostDataHelper->expects($this->any())
            ->method('getPostData')
            ->with($storeSwitchUrl, ['___store' => 'new-store', 'uenc' => null, '___from_store' => 'old-store']);

        $this->switcher->getTargetStorePostData($store);
    }

    /**
     * @dataProvider isStoreInUrlDataProvider
     * @param bool $isUseStoreInUrl
     */
    public function testIsStoreInUrl($isUseStoreInUrl)
    {
        $storeMock = $this->createMock(\Magento\Store\Model\Store::class);

        $storeMock->expects($this->once())->method('isUseStoreInUrl')->willReturn($isUseStoreInUrl);

        $this->storeManager->expects($this->any())->method('getStore')->willReturn($storeMock);
        $this->assertEquals($this->switcher->isStoreInUrl(), $isUseStoreInUrl);
        // check value is cached
        $this->assertEquals($this->switcher->isStoreInUrl(), $isUseStoreInUrl);
    }

    /**
     * @see self::testIsStoreInUrlDataProvider()
     * @return array
     */
    public function isStoreInUrlDataProvider()
    {
        return [[true], [false]];
    }
}
