<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Braintree\Test\Unit\Model;

use Magento\Braintree\Model\CvvEmsCodeMapper;
use Magento\Braintree\Model\Ui\ConfigProvider;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use PHPUnit\Framework\MockObject\MockObject as MockObject;

class CvvEmsCodeMapperTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var CvvEmsCodeMapper
     */
    private $mapper;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->mapper = new CvvEmsCodeMapper();
    }

    /**
     * Checks different variations for cvv codes mapping.
     *
     * @covers \Magento\Braintree\Model\CvvEmsCodeMapper::getCode
     * @param string $cvvCode
     * @param string $expected
     * @dataProvider getCodeDataProvider
     */
    public function testGetCode($cvvCode, $expected)
    {
        /** @var OrderPaymentInterface|MockObject $orderPayment */
        $orderPayment = $this->getMockBuilder(OrderPaymentInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $orderPayment->expects(self::once())
            ->method('getMethod')
            ->willReturn(ConfigProvider::CODE);

        $orderPayment->expects(self::once())
            ->method('getAdditionalInformation')
            ->willReturn(['cvvResponseCode' => $cvvCode]);

        self::assertEquals($expected, $this->mapper->getCode($orderPayment));
    }

    /**
     * Checks a test case, when payment order is not Braintree payment method.
     *
     * @covers \Magento\Braintree\Model\CvvEmsCodeMapper::getCode
     */
    public function testGetCodeWithException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "some_payment" does not supported by Braintree CVV mapper.');

        /** @var OrderPaymentInterface|MockObject $orderPayment */
        $orderPayment = $this->getMockBuilder(OrderPaymentInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $orderPayment->expects(self::exactly(2))
            ->method('getMethod')
            ->willReturn('some_payment');

        $this->mapper->getCode($orderPayment);
    }

    /**
     * Gets variations of cvv codes and expected mapping result.
     *
     * @return array
     */
    public function getCodeDataProvider()
    {
        return [
            ['cvvCode' => '', 'expected' => 'P'],
            ['cvvCode' => null, 'expected' => 'P'],
            ['cvvCode' => 'Unknown', 'expected' => 'P'],
            ['cvvCode' => 'M', 'expected' => 'M'],
            ['cvvCode' => 'N', 'expected' => 'N'],
            ['cvvCode' => 'U', 'expected' => 'P'],
            ['cvvCode' => 'I', 'expected' => 'P'],
            ['cvvCode' => 'S', 'expected' => 'S'],
            ['cvvCode' => 'A', 'expected' => ''],
        ];
    }
}
