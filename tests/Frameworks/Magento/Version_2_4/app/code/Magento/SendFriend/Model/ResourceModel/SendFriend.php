<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\SendFriend\Model\ResourceModel;

/**
 * @api
 * @since 100.0.2
 */
class SendFriend extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * Initialize connection and table
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('sendfriend_log', 'log_id');
    }

    /**
     * Retrieve Sended Emails By Ip
     *
     * @param \Magento\SendFriend\Model\SendFriend $object
     * @param int $ip
     * @param int $startTime
     * @param int $websiteId
     *
     * @return int
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getSendCount($object, $ip, $startTime, $websiteId = null)
    {
        $connection = $this->getConnection();
        $select = $connection->select()->from(
            $this->getMainTable(),
            ['count' => new \Zend_Db_Expr('count(*)')]
        )->where(
            'ip=:ip
                AND  time>=:time
                AND  website_id=:website_id'
        );
        $bind = ['ip' => $ip, 'time' => $startTime, 'website_id' => (int)$websiteId];

        $row = $connection->fetchRow($select, $bind);
        return $row['count'];
    }

    /**
     * Add sended email by ip item
     *
     * @param int $ip
     * @param int $startTime
     * @param int $websiteId
     *
     * @return $this
     */
    public function addSendItem($ip, $startTime, $websiteId)
    {
        $this->getConnection()->insert(
            $this->getMainTable(),
            ['ip' => $ip, 'time' => $startTime, 'website_id' => $websiteId]
        );

        return $this;
    }

    /**
     * Delete Old logs
     *
     * @param int $time
     *
     * @return $this
     */
    public function deleteLogsBefore($time)
    {
        $cond = $this->getConnection()->quoteInto('time<?', $time);
        $this->getConnection()->delete($this->getMainTable(), $cond);

        return $this;
    }
}
