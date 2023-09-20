<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\AuthorizenetAcceptjs\Test\Unit\Gateway\Request;

use Magento\AuthorizenetAcceptjs\Gateway\Request\AddressDataBuilder;
use Magento\AuthorizenetAcceptjs\Gateway\SubjectReader;
use Magento\Payment\Gateway\Data\AddressAdapterInterface;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AddressDataBuilderTest extends TestCase
{
    /**
     * @var AddressDataBuilder
     */
    private $builder;

    /**
     * @var Payment|MockObject
     */
    private $paymentMock;

    /**
     * @var PaymentDataObjectInterface|MockObject
     */
    private $paymentDOMock;

    /**
     * @var OrderAdapterInterface|MockObject
     */
    private $orderMock;

    private $mockAddressData = [
        'firstName' => [
            'method' => 'getFirstname',
            'sampleData' => 'John'
        ],
        'lastName' => [
            'method' => 'getLastname',
            'sampleData' => 'Doe'
        ],
        'company' => [
            'method' => 'getCompany',
            'sampleData' => 'Magento'
        ],
        'address' => [
            'method' => 'getStreetLine1',
            'sampleData' => '11501 Domain Dr'
        ],
        'city' => [
            'method' => 'getCity',
            'sampleData' => 'Austin'
        ],
        'state' => [
            'method' => 'getRegionCode',
            'sampleData' => 'TX'
        ],
        'zip' => [
            'method' => 'getPostcode',
            'sampleData' => '78758'
        ],
        'country' => [
            'method' => 'getCountryId',
            'sampleData' => 'US'
        ],
    ];

    protected function setUp(): void
    {
        $this->paymentDOMock = $this->getMockForAbstractClass(PaymentDataObjectInterface::class);
        $this->paymentMock = $this->createMock(Payment::class);
        $this->paymentDOMock->method('getPayment')
            ->willReturn($this->paymentMock);
        $this->orderMock = $this->getMockForAbstractClass(OrderAdapterInterface::class);
        $this->paymentDOMock->method('getOrder')
            ->willReturn($this->orderMock);

        $this->builder = new AddressDataBuilder(new SubjectReader());
    }

    public function testBuildWithBothAddresses()
    {
        $billingAddress = $this->createAddressMock('billing');
        $shippingAddress = $this->createAddressMock('shipping');
        $this->orderMock->method('getBillingAddress')
            ->willReturn($billingAddress);
        $this->orderMock->method('getShippingAddress')
            ->willReturn($shippingAddress);
        $this->orderMock->method('getRemoteIp')
            ->willReturn('abc');

        $buildSubject = [
            'payment' => $this->paymentDOMock
        ];

        $result = $this->builder->build($buildSubject);

        $this->validateAddressData($result['transactionRequest']['billTo'], 'billing');
        $this->validateAddressData($result['transactionRequest']['shipTo'], 'shipping');
        $this->assertEquals('abc', $result['transactionRequest']['customerIP']);
    }

    /**
     * @param $responseData
     * @param $addressPrefix
     */
    private function validateAddressData($responseData, $addressPrefix)
    {
        foreach ($this->mockAddressData as $fieldValue => $field) {
            $this->assertEquals($addressPrefix . $field['sampleData'], $responseData[$fieldValue]);
        }
    }

    /**
     * @param $prefix
     *
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function createAddressMock($prefix)
    {
        $addressAdapterMock = $this->getMockForAbstractClass(AddressAdapterInterface::class);

        foreach ($this->mockAddressData as $field) {
            $addressAdapterMock->method($field['method'])
                ->willReturn($prefix . $field['sampleData']);
        }

        return $addressAdapterMock;
    }
}
