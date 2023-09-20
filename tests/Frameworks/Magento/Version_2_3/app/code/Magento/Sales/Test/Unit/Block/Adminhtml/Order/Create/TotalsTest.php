<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Sales\Test\Unit\Block\Adminhtml\Order\Create;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * Totals block test
 */
class TotalsTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManager;

    /**
     * @var \Magento\Sales\Block\Adminhtml\Order\Create\Totals
     */
    protected $totals;

    /**
     * @var \Magento\Quote\Model\Quote|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteMock;

    /**
     * @var \Magento\Backend\Model\Session\Quote|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $sessionQuoteMock;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);

        $this->quoteMock = $this->createPartialMock(\Magento\Quote\Model\Quote::class, ['getCustomerNoteNotify']);
        $this->sessionQuoteMock = $this->createMock(\Magento\Backend\Model\Session\Quote::class);

        $this->sessionQuoteMock->expects($this->any())
            ->method('getQuote')
            ->willReturn($this->quoteMock);

        $this->totals = $this->objectManager->getObject(
            \Magento\Sales\Block\Adminhtml\Order\Create\Totals::class,
            [
                'sessionQuote' => $this->sessionQuoteMock
            ]
        );
    }

    /**
     * @param mixed $customerNoteNotify
     * @param bool $expectedResult
     * @dataProvider getNoteNotifyDataProvider
     */
    public function testGetNoteNotify($customerNoteNotify, $expectedResult)
    {
        $this->quoteMock->expects($this->any())
            ->method('getCustomerNoteNotify')
            ->willReturn($customerNoteNotify);

        $this->assertEquals($expectedResult, $this->totals->getNoteNotify());
    }

    /**
     * @return array
     */
    public function getNoteNotifyDataProvider()
    {
        return [
            [0, false],
            [1, true],
            ['0', false],
            ['1', true],
            [null, true]
        ];
    }
}
