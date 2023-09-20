<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Security\Model\Plugin;

use Magento\Security\Model\AdminSessionsManager;

/**
 * Magento\Backend\Model\Auth decorator
 */
class Auth
{
    /**
     * @var AdminSessionsManager
     */
    protected $sessionsManager;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @param AdminSessionsManager $sessionsManager
     * @param \Magento\Framework\Message\ManagerInterface $messageManager
     */
    public function __construct(
        AdminSessionsManager $sessionsManager,
        \Magento\Framework\Message\ManagerInterface $messageManager
    ) {
        $this->sessionsManager = $sessionsManager;
        $this->messageManager = $messageManager;
    }

    /**
     * @param \Magento\Backend\Model\Auth $authModel
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterLogin(\Magento\Backend\Model\Auth $authModel)
    {
        $this->sessionsManager->processLogin();
        if ($this->sessionsManager->getCurrentSession()->isOtherSessionsTerminated()) {
            $this->messageManager->addWarning(__('All other open sessions for this account were terminated.'));
        }
    }

    /**
     * @param \Magento\Backend\Model\Auth $authModel
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeLogout(\Magento\Backend\Model\Auth $authModel)
    {
        $this->sessionsManager->processLogout();
    }
}
