<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\DB;

use Magento\Framework\Flag;

class TransactionTest extends \PHPUnit\Framework\TestCase
{
    protected $objectManager;

    /**
     * @var \Magento\Framework\DB\Transaction
     */
    protected $_model;

    protected function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->_model = $this->objectManager
            ->create(\Magento\Framework\DB\Transaction::class);
    }

    /**
     * @magentoAppArea adminhtml
     */
    public function testSaveDelete()
    {
        /** @var Flag $first */
        $first = $this->objectManager->create(Flag::class, ['data' => ['flag_code' => 'test1']]);
        $first->setFlagData('test1data');
        $second = $this->objectManager->create(Flag::class, ['data' => ['flag_code' => 'test2']]);
        $second->setFlagData('test2data');

        $first->save();
        $this->_model->addObject($first)->addObject($second, 'second');
        $this->_model->save();
        $this->assertNotEmpty($first->getId());
        $this->assertNotEmpty($second->getId());

        $this->_model->delete();

        $test = $this->objectManager->create(Flag::class);
        $test->load($first->getId());
        $this->assertEmpty($test->getId());
    }

    /**
     * @magentoDbIsolation disabled
     */
    public function testTransactionLevelDbIsolationDisable()
    {
        $resourceConnection = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->get(\Magento\Framework\App\ResourceConnection::class);
        $this->assertEquals(0, $resourceConnection->getConnection('default')->getTransactionLevel());
    }

    /**
     * @magentoDbIsolation enabled
     */
    public function testTransactionLevelDbIsolationEnabled()
    {
        $resourceConnection = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->get(\Magento\Framework\App\ResourceConnection::class);
        $this->assertEquals(1, $resourceConnection->getConnection('default')->getTransactionLevel());
    }

    public function testTransactionLevelDbIsolationDefault()
    {
        $resourceConnection = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->get(\Magento\Framework\App\ResourceConnection::class);
        $this->assertEquals(0, $resourceConnection->getConnection('default')->getTransactionLevel());
    }
}
