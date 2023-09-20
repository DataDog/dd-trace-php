<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Authorization\Test\Unit\Policy;

class DefaultTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Authorization\Policy\DefaultPolicy
     */
    protected $_model;

    protected function setUp(): void
    {
        $this->_model = new \Magento\Framework\Authorization\Policy\DefaultPolicy();
    }

    public function testIsAllowedReturnsTrueForAnyResource()
    {
        $this->assertTrue($this->_model->isAllowed('any_role', 'any_resource'));
    }
}
