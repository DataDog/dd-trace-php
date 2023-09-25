<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Payment\Test\Unit\Model\Source;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Source\Invoice;
use PHPUnit\Framework\TestCase;

class InvoiceTest extends TestCase
{
    /**
     * @var Invoice
     */
    protected $_model;

    protected function setUp(): void
    {
        $this->_model = new Invoice();
    }

    public function testToOptionArray()
    {
        $expectedResult = [
            [
                'value' => AbstractMethod::ACTION_AUTHORIZE_CAPTURE,
                'label' => __('Yes'),
            ],
            ['value' => '', 'label' => __('No')],
        ];

        $this->assertEquals($expectedResult, $this->_model->toOptionArray());
    }
}
