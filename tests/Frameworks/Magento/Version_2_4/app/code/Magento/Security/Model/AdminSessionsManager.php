<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Security\Model;

use Magento\Backend\Model\Auth\Session;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\DateTime;
use Magento\Security\Model\ResourceModel\AdminSessionInfo\Collection;
use Magento\Security\Model\ResourceModel\AdminSessionInfo\CollectionFactory;

/**
 * Admin Sessions Manager Model
 *
 * @api
 * @since 100.1.0
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 */
class AdminSessionsManager
{
    /**
     * Admin Session lifetime (sec)
     */
    public const ADMIN_SESSION_LIFETIME = 86400;

    /**
     * Logout reason when current user has been locked out
     */
    public const LOGOUT_REASON_USER_LOCKED = 10;

    /**
     * @var ConfigInterface
     * @since 100.1.0
     */
    protected $securityConfig;

    /**
     * @var Session
     * @since 100.1.0
     */
    protected $authSession;

    /**
     * @var AdminSessionInfoFactory
     * @since 100.1.0
     */
    protected $adminSessionInfoFactory;

    /**
     * @var \Magento\Security\Model\ResourceModel\AdminSessionInfo\CollectionFactory
     * @since 100.1.0
     */
    protected $adminSessionInfoCollectionFactory;

    /**
     * @var \Magento\Security\Model\AdminSessionInfo
     * @since 100.1.0
     */
    protected $currentSession;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    private $dateTime;

    /**
     * @var RemoteAddress
     */
    private $remoteAddress;

    /**
     * Max lifetime for session prolong to be valid (sec)
     *
     * Means that after session was prolonged
     * all other prolongs will be ignored within this period
     *
     * @var int
     */
    private $maxIntervalBetweenConsecutiveProlongs = 60;

    /**
     * @param ConfigInterface $securityConfig
     * @param Session $authSession
     * @param AdminSessionInfoFactory $adminSessionInfoFactory
     * @param CollectionFactory $adminSessionInfoCollectionFactory
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $dateTime
     * @param RemoteAddress $remoteAddress
     */
    public function __construct(
        ConfigInterface $securityConfig,
        Session $authSession,
        \Magento\Security\Model\AdminSessionInfoFactory $adminSessionInfoFactory,
        \Magento\Security\Model\ResourceModel\AdminSessionInfo\CollectionFactory $adminSessionInfoCollectionFactory,
        \Magento\Framework\Stdlib\DateTime\DateTime $dateTime,
        RemoteAddress $remoteAddress
    ) {
        $this->securityConfig = $securityConfig;
        $this->authSession = $authSession;
        $this->adminSessionInfoFactory = $adminSessionInfoFactory;
        $this->adminSessionInfoCollectionFactory = $adminSessionInfoCollectionFactory;
        $this->dateTime = $dateTime;
        $this->remoteAddress = $remoteAddress;
    }

    /**
     * Handle all others active sessions according Sharing Account Setting
     *
     * @return $this
     * @since 100.1.0
     */
    public function processLogin()
    {
        $this->createNewSession();

        $olderThen = $this->dateTime->gmtTimestamp() - $this->securityConfig->getAdminSessionLifetime();
        if (!$this->securityConfig->isAdminAccountSharingEnabled()) {
            $result = $this->createAdminSessionInfoCollection()->updateActiveSessionsStatus(
                AdminSessionInfo::LOGGED_OUT_BY_LOGIN,
                $this->getCurrentSession()->getUserId(),
                $this->getCurrentSession()->getId(),
                $olderThen
            );
            if ($result) {
                $this->getCurrentSession()->setIsOtherSessionsTerminated(true);
            }
        }

        return $this;
    }

    /**
     * Handle Prolong process
     *
     * @return $this
     * @since 100.1.0
     */
    public function processProlong()
    {
        if ($this->lastProlongIsOldEnough()) {
            $this->getCurrentSession()->setData(
                'updated_at',
                date(
                    DateTime::DATETIME_PHP_FORMAT,
                    $this->authSession->getUpdatedAt()
                )
            );
            $this->getCurrentSession()->save();
        }

        return $this;
    }

    /**
     * Handle logout process
     *
     * @return $this
     * @since 100.1.0
     */
    public function processLogout()
    {
        $this->getCurrentSession()->setData(
            'status',
            AdminSessionInfo::LOGGED_OUT
        );
        $this->getCurrentSession()->save();

        return $this;
    }

    /**
     * Get current session record
     *
     * @return AdminSessionInfo
     * @since 100.1.0
     */
    public function getCurrentSession()
    {
        if (!$this->currentSession) {
            $adminSessionInfoId = $this->authSession->getAdminSessionInfoId();
            if (!$adminSessionInfoId) {
                $this->createNewSession();
                $adminSessionInfoId = $this->authSession->getAdminSessionInfoId();
                $this->logoutOtherUserSessions();
            }

            $this->currentSession = $this->adminSessionInfoFactory->create();
            $this->currentSession->load($adminSessionInfoId, 'id');
        }

        return $this->currentSession;
    }

    /**
     * Get logout reason message by status
     *
     * @param int $statusCode
     * @return string
     * @since 100.1.0
     */
    public function getLogoutReasonMessageByStatus($statusCode)
    {
        switch ((int)$statusCode) {
            case AdminSessionInfo::LOGGED_IN:
                $reasonMessage = null;
                break;
            case AdminSessionInfo::LOGGED_OUT_BY_LOGIN:
                $reasonMessage = __(
                    'Someone logged into this account from another device or browser.'
                    . ' Your current session is terminated.'
                );
                break;
            case AdminSessionInfo::LOGGED_OUT_MANUALLY:
                $reasonMessage = __(
                    'Your current session is terminated by another user of this account.'
                );
                break;
            case self::LOGOUT_REASON_USER_LOCKED:
                $reasonMessage = __(
                    'Your account is temporarily disabled. Please try again later.'
                );
                break;
            default:
                $reasonMessage = __('Your current session has been expired.');
                break;
        }

        return $reasonMessage;
    }

    /**
     * Get message with explanation of logout reason
     *
     * @return string
     * @since 100.1.0
     */
    public function getLogoutReasonMessage()
    {
        return $this->getLogoutReasonMessageByStatus(
            $this->getCurrentSession()->getStatus()
        );
    }

    /**
     * Get sessions for current user
     *
     * @return Collection
     * @since 100.1.0
     */
    public function getSessionsForCurrentUser()
    {
        return $this->createAdminSessionInfoCollection()
            ->filterByUser($this->authSession->getUser()->getId(), \Magento\Security\Model\AdminSessionInfo::LOGGED_IN)
            ->filterExpiredSessions($this->securityConfig->getAdminSessionLifetime())
            ->loadData();
    }

    /**
     * Logout another user sessions
     *
     * @return $this
     * @since 100.1.0
     */
    public function logoutOtherUserSessions()
    {
        $user = $this->authSession->getUser();
        if ($user) {
            $collection = $this->createAdminSessionInfoCollection()
                ->filterByUser(
                    $user->getId(),
                    \Magento\Security\Model\AdminSessionInfo::LOGGED_IN,
                    $this->authSession->getAdminSessionInfoId()
                )
                ->filterExpiredSessions($this->securityConfig->getAdminSessionLifetime())
                ->loadData();

            $collection->setDataToAll('status', \Magento\Security\Model\AdminSessionInfo::LOGGED_OUT_MANUALLY)
                ->save();
        }

        return $this;
    }

    /**
     * Clean expired Admin Sessions
     *
     * @return $this
     * @since 100.1.0
     */
    public function cleanExpiredSessions()
    {
        $this->createAdminSessionInfoCollection()->deleteSessionsOlderThen(
            $this->dateTime->gmtTimestamp() - self::ADMIN_SESSION_LIFETIME
        );

        return $this;
    }

    /**
     * Create new record
     *
     * @return $this
     * @since 100.1.0
     */
    protected function createNewSession()
    {
        $user = $this->authSession->getUser();
        $adminSessionInfo = $this->adminSessionInfoFactory
            ->create()
            ->setData(
                [
                    'user_id' => $user ? $user->getId() : null,
                    'ip' => $this->remoteAddress->getRemoteAddress(),
                    'status' => AdminSessionInfo::LOGGED_IN
                ]
            )->save();

        $this->authSession->setAdminSessionInfoId($adminSessionInfo->getId());

        return $this;
    }

    /**
     * Retrieve new instance of admin session info collection
     *
     * @return Collection
     * @since 100.1.0
     */
    protected function createAdminSessionInfoCollection()
    {
        return $this->adminSessionInfoCollectionFactory->create();
    }

    /**
     * Calculates diff between now and last session updated_at and decides whether new prolong must be triggered or not
     *
     * This is done to limit amount of session prolongs and updates to database
     * within some period of time - X
     * X - is calculated in getIntervalBetweenConsecutiveProlongs()
     *
     * @return bool
     * @see getIntervalBetweenConsecutiveProlongs()
     */
    private function lastProlongIsOldEnough()
    {
        $lastUpdatedTime = $this->getCurrentSession()->getUpdatedAt();
        if ($lastUpdatedTime === null || is_numeric($lastUpdatedTime)) {
            $lastUpdatedTime = "now";
        }
        $lastProlongTimestamp = strtotime($lastUpdatedTime);
        $nowTimestamp = $this->authSession->getUpdatedAt();

        $diff = $nowTimestamp - $lastProlongTimestamp;

        return (float)$diff > $this->getIntervalBetweenConsecutiveProlongs();
    }

    /**
     * Calculates lifetime for session prolong to be valid
     *
     * Calculation is based on admin session lifetime
     * Calculated result is in seconds and is in the interval
     * between 1 (including) and MAX_INTERVAL_BETWEEN_CONSECUTIVE_PROLONGS (including)
     *
     * @return float
     */
    private function getIntervalBetweenConsecutiveProlongs()
    {
        return (float)max(
            1,
            min(
                4 * log((float)$this->securityConfig->getAdminSessionLifetime()),
                $this->maxIntervalBetweenConsecutiveProlongs
            )
        );
    }
}
