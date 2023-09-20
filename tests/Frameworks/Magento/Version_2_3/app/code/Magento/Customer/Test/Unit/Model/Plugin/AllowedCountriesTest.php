<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Test\Unit\Model\Plugin;

use Magento\Customer\Model\Config\Share;
use Magento\Customer\Model\Plugin\AllowedCountries;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class AllowedCountriesTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Customer\Model\Config\Share | \PHPUnit\Framework\MockObject\MockObject
     */
    private $shareConfig;

    /**
     * @var StoreManagerInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    private $storeManager;

    /** @var  AllowedCountries */
    private $plugin;

    protected function setUp(): void
    {
        $this->shareConfig = $this->getMockBuilder(Share::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->storeManager = $this->getMockForAbstractClass(StoreManagerInterface::class);

        $this->plugin = new AllowedCountries($this->shareConfig, $this->storeManager);
    }

    public function testGetAllowedCountriesWithGlobalScope()
    {
        $expectedFilter = 1;
        $expectedScope = ScopeInterface::SCOPE_WEBSITES;

        $this->shareConfig->expects($this->once())
            ->method('isGlobalScope')
            ->willReturn(true);
        $originalAllowedCountriesMock = $this->getMockBuilder(\Magento\Directory\Model\AllowedCountries::class)
            ->disableOriginalConstructor()
            ->getMock();
        $websiteMock = $this->getMockForAbstractClass(WebsiteInterface::class);
        $websiteMock->expects($this->once())
            ->method('getId')
            ->willReturn($expectedFilter);
        $this->storeManager->expects($this->once())
            ->method('getWebsites')
            ->willReturn([$websiteMock]);

        $this->assertEquals(
            [$expectedScope, [$expectedFilter]],
            $this->plugin->beforeGetAllowedCountries($originalAllowedCountriesMock)
        );
    }
}
