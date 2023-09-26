<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Integration\Model\ResourceModel\Oauth;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Encryption\Encryptor;

class Consumer extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{

    /**
     * @var Encryptor
     */
    private $encryptor;

    /**
     * @param \Magento\Framework\Model\ResourceModel\Db\Context $context
     * @param string $connectionName
     * @param Encryptor $encryptor
     */
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        $connectionName = null,
        Encryptor $encryptor = null
    ) {
        parent::__construct($context, $connectionName);
        $this->encryptor = $encryptor ?? ObjectManager::getInstance()->get(Encryptor::class);
    }

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('oauth_consumer', 'entity_id');
    }

    /**
     * Delete all Nonce entries associated with the consumer
     *
     * @param \Magento\Framework\Model\AbstractModel $object
     * @return $this
     */
    public function _afterDelete(\Magento\Framework\Model\AbstractModel $object)
    {
        $connection = $this->getConnection();
        $connection->delete($this->getTable('oauth_nonce'), ['consumer_id = ?' => (int)$object->getId()]);
        $connection->delete($this->getTable('oauth_token'), ['consumer_id = ?' => (int)$object->getId()]);
        return parent::_afterDelete($object);
    }

    /**
     * Compute time in seconds since consumer was created.
     *
     * @deprecated 100.0.6
     *
     * @param int $consumerId - The consumer id
     * @return int - time lapsed in seconds
     */
    public function getTimeInSecondsSinceCreation($consumerId)
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->reset(\Magento\Framework\DB\Select::COLUMNS)
            ->columns(new \Zend_Db_Expr('CURRENT_TIMESTAMP() - created_at'))
            ->where('entity_id = ?', $consumerId);

        return $connection->fetchOne($select);
    }

    /**
     * @inheritdoc
     */
    protected function _beforeSave(\Magento\Framework\Model\AbstractModel $object)
    {
        if ($object->getSecret()) {
            $object->setSecret($this->encryptor->encrypt($object->getSecret()));
        }

        return parent::_beforeSave($object);
    }

    /**
     * @inheritdoc
     */
    protected function _afterLoad(\Magento\Framework\Model\AbstractModel $object)
    {
        if ($object->getSecret()) {
            $object->setSecret($this->encryptor->decrypt($object->getSecret()));
        }

        return parent::_afterLoad($object);
    }

    /**
     * @inheritdoc
     */
    protected function _afterSave(\Magento\Framework\Model\AbstractModel $object)
    {
        if ($object->getSecret()) {
            $object->setSecret($this->encryptor->decrypt($object->getSecret()));
        }

        return parent::_afterSave($object);
    }
}
