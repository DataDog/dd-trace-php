<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Payment\Test\Unit\Model\Checks;

use Magento\Payment\Model\Checks\CanUseForCountry;

class CanUseForCountryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Expected country id
     */
    const EXPECTED_COUNTRY_ID = 1;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $countryProvider;

    /**
     * @var CanUseForCountry
     */
    protected $_model;

    protected function setUp(): void
    {
        $this->countryProvider = $this->createMock(
            \Magento\Payment\Model\Checks\CanUseForCountry\CountryProvider::class
        );
        $this->_model = new CanUseForCountry($this->countryProvider);
    }

    /**
     * @dataProvider paymentMethodDataProvider
     * @param bool $expectation
     */
    public function testIsApplicable($expectation)
    {
        $quoteMock = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)->disableOriginalConstructor()->setMethods(
            []
        )->getMock();

        $paymentMethod = $this->getMockBuilder(
            \Magento\Payment\Model\MethodInterface::class
        )->disableOriginalConstructor()->setMethods([])->getMock();
        $paymentMethod->expects($this->once())->method('canUseForCountry')->with(
            self::EXPECTED_COUNTRY_ID
        )->willReturn($expectation);
        $this->countryProvider->expects($this->once())->method('getCountry')->willReturn(self::EXPECTED_COUNTRY_ID);

        $this->assertEquals($expectation, $this->_model->isApplicable($paymentMethod, $quoteMock));
    }

    /**
     * @return array
     */
    public function paymentMethodDataProvider()
    {
        return [[true], [false]];
    }
}
