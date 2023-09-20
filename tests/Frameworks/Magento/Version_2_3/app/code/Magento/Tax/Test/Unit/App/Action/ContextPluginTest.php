<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Tax\Test\Unit\App\Action;

/**
 * Context plugin test
 */
class ContextPluginTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Tax\Helper\Data
     */
    protected $taxHelperMock;

    /**
     * @var \Magento\Weee\Helper\Data
     */
    protected $weeeHelperMock;

    /**
     * @var \Magento\Weee\Model\Tax
     */
    protected $weeeTaxMock;

    /**
     * @var \Magento\Framework\App\Http\Context
     */
    protected $httpContextMock;

    /**
     * @var \Magento\Tax\Model\Calculation\Proxy
     */
    protected $taxCalculationMock;

    /**
     * Module manager
     *
     * @var \Magento\Framework\Module\Manager
     */
    private $moduleManagerMock;

    /**
     * Cache config
     *
     * @var \Magento\PageCache\Model\Config
     */
    private $cacheConfigMock;

    /**
     * @var \Magento\Tax\Model\App\Action\ContextPlugin
     */
    protected $contextPlugin;

    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->taxHelperMock = $this->getMockBuilder(\Magento\Tax\Helper\Data::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->weeeHelperMock = $this->getMockBuilder(\Magento\Weee\Helper\Data::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->weeeTaxMock = $this->getMockBuilder(\Magento\Weee\Model\Tax::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->httpContextMock = $this->getMockBuilder(\Magento\Framework\App\Http\Context::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->taxCalculationMock = $this->getMockBuilder(\Magento\Tax\Model\Calculation\Proxy::class)
            ->disableOriginalConstructor()
            ->setMethods(['getTaxRates'])
            ->getMock();

        $this->customerSessionMock = $this->getMockBuilder(\Magento\Customer\Model\Session::class)
            ->disableOriginalConstructor()
            ->setMethods(
                [
                'getDefaultTaxBillingAddress', 'getDefaultTaxShippingAddress', 'getCustomerTaxClassId',
                'getWebsiteId', 'isLoggedIn'
                ]
            )
            ->getMock();

        $this->moduleManagerMock = $this->getMockBuilder(\Magento\Framework\Module\Manager::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->cacheConfigMock = $this->getMockBuilder(\Magento\PageCache\Model\Config::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->contextPlugin = $this->objectManager->getObject(
            \Magento\Tax\Model\App\Action\ContextPlugin::class,
            [
                'customerSession' => $this->customerSessionMock,
                'httpContext' => $this->httpContextMock,
                'calculation' => $this->taxCalculationMock,
                'weeeTax' => $this->weeeTaxMock,
                'taxHelper' => $this->taxHelperMock,
                'weeeHelper' => $this->weeeHelperMock,
                'moduleManager' => $this->moduleManagerMock,
                'cacheConfig' => $this->cacheConfigMock
            ]
        );
    }

    /**
     * @param bool $cache
     * @param bool $taxEnabled
     * @param bool $loggedIn
     * @dataProvider beforeDispatchDataProvider
     */
    public function testBeforeDispatch($cache, $taxEnabled, $loggedIn)
    {
        $this->customerSessionMock->expects($this->any())
            ->method('isLoggedIn')
            ->willReturn($loggedIn);

        $this->moduleManagerMock->expects($this->any())
            ->method('isEnabled')
            ->with('Magento_PageCache')
            ->willReturn($cache);

        $this->cacheConfigMock->expects($this->any())
            ->method('isEnabled')
            ->willReturn($cache);

        if ($cache && $loggedIn) {
            $this->taxHelperMock->expects($this->any())
                ->method('isCatalogPriceDisplayAffectedByTax')
                ->willReturn($taxEnabled);

            if ($taxEnabled) {
                $this->customerSessionMock->expects($this->once())
                    ->method('getDefaultTaxBillingAddress')
                    ->willReturn(['country_id' => 1, 'region_id' => 1, 'postcode' => 11111]);
                $this->customerSessionMock->expects($this->once())
                    ->method('getDefaultTaxShippingAddress')
                    ->willReturn(['country_id' => 1, 'region_id' => 1, 'postcode' => 11111]);
                $this->customerSessionMock->expects($this->once())
                    ->method('getCustomerTaxClassId')
                    ->willReturn(1);

                $this->taxCalculationMock->expects($this->once())
                    ->method('getTaxRates')
                    ->with(
                        ['country_id' => 1, 'region_id' => 1, 'postcode' => 11111],
                        ['country_id' => 1, 'region_id' => 1, 'postcode' => 11111],
                        1
                    )
                    ->willReturn([]);

                $this->httpContextMock->expects($this->any())
                    ->method('setValue')
                    ->with('tax_rates', [], 0);
            }

            $action = $this->objectManager->getObject(\Magento\Framework\App\Test\Unit\Action\Stub\ActionStub::class);
            $request = $this->createPartialMock(\Magento\Framework\App\Request\Http::class, ['getActionName']);
            $result = $this->contextPlugin->beforeDispatch($action, $request);
            $this->assertNull($result);
        } else {
            $this->assertFalse($loggedIn);
        }
    }

    /**
     * @return array
     */
    public function beforeDispatchDataProvider()
    {
        return [
            [false, false, false],
            [true, true, false],
            [true, true, true],
            [true, false, true],
            [true, true, true]
        ];
    }
}
