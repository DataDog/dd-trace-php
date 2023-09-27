<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Newsletter\Model\ResourceModel;

use Magento\Framework\App\ObjectManager;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Newsletter subscriber resource model
 *
 * @author      Magento Core Team <core@magentocommerce.com>
 *
 * @api
 * @since 100.0.2
 */
class Subscriber extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * DB connection
     *
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected $connection;

    /**
     * Name of subscriber link DB table
     *
     * @var string
     */
    protected $_subscriberLinkTable;

    /**
     * Name of scope for error messages
     *
     * @var string
     */
    protected $_messagesScope = 'newsletter/session';

    /**
     * Date
     *
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $_date;

    /**
     * @var \Magento\Framework\Math\Random
     */
    protected $mathRandom;

    /**
     * Store manager
     *
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * Construct
     *
     * @param \Magento\Framework\Model\ResourceModel\Db\Context $context
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $date
     * @param \Magento\Framework\Math\Random $mathRandom
     * @param string $connectionName
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        \Magento\Framework\Stdlib\DateTime\DateTime $date,
        \Magento\Framework\Math\Random $mathRandom,
        $connectionName = null,
        StoreManagerInterface $storeManager = null
    ) {
        $this->_date = $date;
        $this->mathRandom = $mathRandom;
        $this->storeManager = $storeManager ?: ObjectManager::getInstance()->get(StoreManagerInterface::class);
        parent::__construct($context, $connectionName);
    }

    /**
     * Initialize resource model. Get tablename from config
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('newsletter_subscriber', 'subscriber_id');
        $this->_subscriberLinkTable = $this->getTable('newsletter_queue_link');
        $this->connection = $this->getConnection();
    }

    /**
     * Set error messages scope
     *
     * @param string $scope
     * @return void
     */
    public function setMessagesScope($scope)
    {
        $this->_messagesScope = $scope;
    }

    /**
     * Load subscriber from DB by email
     *
     * @param string $subscriberEmail
     * @return array
     */
    public function loadByEmail($subscriberEmail)
    {
        $select = $this->connection->select()->from($this->getMainTable())->where('subscriber_email=:subscriber_email');

        $result = $this->connection->fetchRow($select, ['subscriber_email' => $subscriberEmail]);

        if (!$result) {
            return [];
        }

        return $result;
    }

    /**
     * Load subscriber by customer
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface $customer
     * @return array
     */
    public function loadByCustomerData(\Magento\Customer\Api\Data\CustomerInterface $customer)
    {
        $storeIds = $this->storeManager->getWebsite()->getStoreIds();

        if ($customer->getId()) {
            $select = $this->connection
                ->select()
                ->from($this->getMainTable())
                ->where('customer_id = ?', $customer->getId())
                ->where('store_id IN (?)', $storeIds)
                ->limit(1);

            $result = $this->connection->fetchRow($select);

            if ($result) {
                return $result;
            }
        }

        if ($customer->getEmail()) {
            $select = $this->connection
                ->select()
                ->from($this->getMainTable())
                ->where('subscriber_email = ?', $customer->getEmail())
                ->where('store_id IN (?)', $storeIds)
                ->limit(1);

            $result = $this->connection->fetchRow($select);

            if ($result) {
                return $result;
            }
        }

        return [];
    }

    /**
     * Generates random code for subscription confirmation
     *
     * @return string
     */
    protected function _generateRandomCode()
    {
        return $this->mathRandom->getUniqueHash();
    }

    /**
     * Updates data when subscriber received
     *
     * @param \Magento\Newsletter\Model\Subscriber $subscriber
     * @param \Magento\Newsletter\Model\Queue $queue
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function received(\Magento\Newsletter\Model\Subscriber $subscriber, \Magento\Newsletter\Model\Queue $queue)
    {
        $this->connection->beginTransaction();
        try {
            $data['letter_sent_at'] = $this->_date->gmtDate();
            $this->connection->update(
                $this->_subscriberLinkTable,
                $data,
                ['subscriber_id = ?' => $subscriber->getId(), 'queue_id = ?' => $queue->getId()]
            );
            $this->connection->commit();
        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw new \Magento\Framework\Exception\LocalizedException(__('We cannot mark as received subscriber.'));
        }
        return $this;
    }
}
