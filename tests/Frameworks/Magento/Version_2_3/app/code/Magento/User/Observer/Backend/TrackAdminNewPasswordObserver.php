<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\User\Observer\Backend;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\User\Model\User;

/**
 * User backend observer model for passwords
 */
class TrackAdminNewPasswordObserver implements ObserverInterface
{
    /**
     * Backend configuration interface
     *
     * @var \Magento\User\Model\Backend\Config\ObserverConfig
     */
    protected $observerConfig;

    /**
     * Admin user resource model
     *
     * @var \Magento\User\Model\ResourceModel\User
     */
    protected $userResource;

    /**
     * Backend authorization session
     *
     * @var \Magento\Backend\Model\Auth\Session
     */
    protected $authSession;

    /**
     * Message manager interface
     *
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @param \Magento\User\Model\Backend\Config\ObserverConfig $observerConfig
     * @param \Magento\User\Model\ResourceModel\User $userResource
     * @param \Magento\Backend\Model\Auth\Session $authSession
     * @param \Magento\Framework\Message\ManagerInterface $messageManager
     */
    public function __construct(
        \Magento\User\Model\Backend\Config\ObserverConfig $observerConfig,
        \Magento\User\Model\ResourceModel\User $userResource,
        \Magento\Backend\Model\Auth\Session $authSession,
        \Magento\Framework\Message\ManagerInterface $messageManager
    ) {
        $this->observerConfig = $observerConfig;
        $this->userResource = $userResource;
        $this->authSession = $authSession;
        $this->messageManager = $messageManager;
    }

    /**
     * Save current admin password to prevent its usage when changed in the future.
     *
     * @param EventObserver $observer
     * @return void
     */
    public function execute(EventObserver $observer)
    {
        /* @var $user \Magento\User\Model\User */
        $user = $observer->getEvent()->getObject();
        if ($user->getId()) {
            $passwordHash = $user->getPassword();
            if ($passwordHash && !$user->getForceNewPassword()) {
                $this->userResource->trackPassword($user, $passwordHash);
                $this->messageManager->getMessages()->deleteMessageByIdentifier(User::MESSAGE_ID_PASSWORD_EXPIRED);
                $this->authSession->unsPciAdminUserIsPasswordExpired();
            }
        }
    }
}
