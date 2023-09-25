<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

// @codingStandardsIgnoreFile

/**
 * See \Magento\TestFramework\Db\Adapter\TransactionInterface
 */
namespace Magento\TestFramework\Db\Adapter;

class Mysql extends \Magento\Framework\DB\Adapter\Pdo\Mysql implements \Magento\TestFramework\Db\Adapter\TransactionInterface
{
    /**
     * @var int
     */
    protected $_levelAdjustment = 0;

    /**
     * See \Magento\TestFramework\Db\Adapter\TransactionInterface
     *
     * @return \Magento\TestFramework\Db\Adapter\Mysql
     */
    public function beginTransparentTransaction()
    {
        $this->_levelAdjustment += 1;
        return $this->beginTransaction();
    }

    /**
     * See \Magento\TestFramework\Db\Adapter\TransactionInterface
     *
     * @return \Magento\TestFramework\Db\Adapter\Mysql
     */
    public function commitTransparentTransaction()
    {
        $this->_levelAdjustment -= 1;
        return $this->commit();
    }

    /**
     * See \Magento\TestFramework\Db\Adapter\TransactionInterface
     *
     * @return \Magento\TestFramework\Db\Adapter\Mysql
     */
    public function rollbackTransparentTransaction()
    {
        $this->_levelAdjustment -= 1;
        return $this->rollback();
    }
}
