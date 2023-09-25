<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\AuthorizenetAcceptjs\Test\Unit\Gateway\Request;

use Magento\AuthorizenetAcceptjs\Gateway\Request\AcceptFdsDataBuilder;
use Magento\AuthorizenetAcceptjs\Gateway\SubjectReader;
use Magento\Sales\Model\Order\Payment\Transaction;
use PHPUnit\Framework\TestCase;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;

class AcceptFdsDataBuilderTest extends TestCase
{
    /**
     * @var AcceptFdsDataBuilder
     */
    private $builder;

    /**
     * @var Payment|MockObject
     */
    private $paymentMock;

    /**
     * @var Payment|MockObject
     */
    private $paymentDOMock;

    /**
     * @var Order|MockObject
     */
    private $orderMock;

    protected function setUp(): void
    {
        $this->paymentDOMock = $this->getMockForAbstractClass(PaymentDataObjectInterface::class);
        $this->paymentMock = $this->createMock(Payment::class);
        $this->orderMock = $this->createMock(Order::class);
        $this->paymentDOMock->method('getPayment')
            ->willReturn($this->paymentMock);

        $this->builder = new AcceptFdsDataBuilder(new SubjectReader());
    }

    public function testBuild()
    {
        $transactionMock = $this->createMock(Transaction::class);

        $this->paymentMock->method('getAuthorizationTransaction')
            ->willReturn($transactionMock);

        $transactionMock->method('getTxnId')
            ->willReturn('foo');

        $expected = [
            'heldTransactionRequest' => [
                'action' => 'approve',
                'refTransId' => 'foo'
            ]
        ];

        $buildSubject = [
            'payment' => $this->paymentDOMock,
        ];

        $this->assertEquals($expected, $this->builder->build($buildSubject));
    }
}
