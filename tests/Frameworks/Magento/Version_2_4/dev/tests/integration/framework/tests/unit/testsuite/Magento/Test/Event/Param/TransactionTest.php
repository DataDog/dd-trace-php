<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Test class for \Magento\TestFramework\Event\Param\Transaction.
 */
namespace Magento\Test\Event\Param;

class TransactionTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\TestFramework\Event\Param\Transaction
     */
    protected $_object;

    protected function setUp(): void
    {
        $this->_object = new \Magento\TestFramework\Event\Param\Transaction();
    }

    public function testConstructor()
    {
        $this->_object->requestTransactionStart();
        $this->_object->requestTransactionRollback();
        $this->_object->__construct($this);
        $this->assertFalse($this->_object->isTransactionStartRequested());
        $this->assertFalse($this->_object->isTransactionRollbackRequested());
    }

    public function testRequestTransactionStart()
    {
        $this->assertFalse($this->_object->isTransactionStartRequested());
        $this->_object->requestTransactionStart();
        $this->assertTrue($this->_object->isTransactionStartRequested());
    }

    public function testRequestTransactionRollback()
    {
        $this->assertFalse($this->_object->isTransactionRollbackRequested());
        $this->_object->requestTransactionRollback();
        $this->assertTrue($this->_object->isTransactionRollbackRequested());
    }
}
