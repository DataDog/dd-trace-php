<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\ObjectManager\Test\Unit;

require __DIR__ . '/_files/Proxy.php';

class InterceptableValidatorTest extends \PHPUnit\Framework\TestCase
{
    public function testValidate()
    {
        $interceptableValidator = new \Magento\Framework\ObjectManager\InterceptableValidator();
        $this->assertFalse(
            $interceptableValidator->validate(
                \Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper\Interceptor::class
            )
        );
        $this->assertFalse(
            $interceptableValidator->validate(
                \Magento\Test\Di\Proxy::class
            )
        );
    }
}
