<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Paypal\Test\Unit\Model\System\Config\Source;

use Magento\Paypal\Model\System\Config\Source\Yesnoshortcut;
use PHPUnit\Framework\TestCase;

class YesnoshortcutTest extends TestCase
{
    /**
     * @var Yesnoshortcut
     */
    protected $_model;

    protected function setUp(): void
    {
        $this->_model = new Yesnoshortcut();
    }

    public function testToOptionArray()
    {
        $expectedResult = [
            ['value' => 1, 'label' => __('Yes (PayPal recommends this option)')],
            ['value' => 0, 'label' => __('No')]
        ];
        $this->assertEquals($expectedResult, $this->_model->toOptionArray());
    }
}
