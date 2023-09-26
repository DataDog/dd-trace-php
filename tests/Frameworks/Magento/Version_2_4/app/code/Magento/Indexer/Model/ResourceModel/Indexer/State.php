<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Indexer\Model\ResourceModel\Indexer;

use Magento\Framework\Indexer\StateInterface;

/**
 * Resource model for indexer state
 */
class State extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * Resource initialization
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('indexer_state', 'state_id');
        $this->addUniqueField(['field' => ['indexer_id'], 'title' => __('State for the same indexer')]);
    }

    /**
     * @inheritDoc
     */
    protected function prepareDataForUpdate($object)
    {
        $data = parent::prepareDataForUpdate($object);

        if (isset($data['status']) && StateInterface::STATUS_VALID === $data['status']) {
            $data['status'] = $this->getConnection()->getCheckSql(
                $this->getConnection()->quoteInto('status = ?', StateInterface::STATUS_WORKING),
                $this->getConnection()->quote($data['status']),
                'status'
            );
        }

        return $data;
    }
}
