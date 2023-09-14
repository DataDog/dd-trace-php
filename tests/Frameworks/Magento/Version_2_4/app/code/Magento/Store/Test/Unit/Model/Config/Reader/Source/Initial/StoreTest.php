<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Store\Test\Unit\Model\Config\Reader\Source\Initial;

use Magento\Framework\App\Config\Initial;
use Magento\Framework\App\Config\Scope\Converter;
use Magento\Store\Model\Config\Reader\Source\Initial\Store;
use Magento\Store\Model\Config\Reader\Source\Initial\Website;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class StoreTest extends TestCase
{
    public function testGet()
    {
        $scopeCode = 'myStore';
        $websiteCode = 'myWebsite';
        $initialConfig = $this->getMockBuilder(Initial::class)
            ->disableOriginalConstructor()
            ->getMock();
        $initialConfig->expects($this->once())
            ->method('getData')
            ->with("stores|$scopeCode")
            ->willReturn([
                'general' => [
                    'locale' => [
                        'code'=> 'en_US'
                    ]
                ]
            ]);
        $websiteSource = $this->getMockBuilder(Website::class)
            ->disableOriginalConstructor()
            ->getMock();
        $websiteSource->expects($this->once())
            ->method('get')
            ->with($websiteCode)
            ->willReturn([
                'general' => [
                    'locale' => [
                        'code'=> 'ru_RU'
                    ]
                ]
            ]);
        $storeManager = $this->getMockBuilder(StoreManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $store = $this->getMockBuilder(\Magento\Store\Model\Store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $store->expects($this->once())
            ->method('getData')
            ->with('website_code')
            ->willReturn('myWebsite');

        $storeManager->expects($this->once())
            ->method('getStore')
            ->with($scopeCode)
            ->willReturn($store);

        $converter = $this->getMockBuilder(Converter::class)
            ->disableOriginalConstructor()
            ->getMock();
        $converter->expects($this->once())
            ->method('convert')
            ->willReturnArgument(0);

        $storeSource = new Store($initialConfig, $websiteSource, $storeManager, $converter);
        $this->assertEquals(
            [
                'general' => [
                    'locale' => [
                        'code'=> 'en_US'
                    ]
                ]
            ],
            $storeSource->get($scopeCode)
        );
    }
}
