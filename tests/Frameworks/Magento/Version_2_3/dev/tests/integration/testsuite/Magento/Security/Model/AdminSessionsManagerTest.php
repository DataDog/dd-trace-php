<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Security\Model;

/**
 * @magentoAppArea adminhtml
 */
class AdminSessionsManagerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Backend\Model\Auth
     */
    protected $auth;

    /**
     * @var \Magento\Backend\Model\Auth\Session
     */
    protected $authSession;

    /**
     * @var \Magento\Security\Model\AdminSessionInfo
     */
    protected $adminSessionInfo;

    /**
     * @var \Magento\Security\Model\AdminSessionsManager
     */
    protected $adminSessionsManager;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * Set up
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->objectManager->get(\Magento\Framework\Config\ScopeInterface::class)
            ->setCurrentScope(\Magento\Backend\App\Area\FrontNameResolver::AREA_CODE);
        $this->auth = $this->objectManager->create(\Magento\Backend\Model\Auth::class);
        $this->authSession = $this->objectManager->create(\Magento\Backend\Model\Auth\Session::class);
        $this->adminSessionInfo = $this->objectManager->create(\Magento\Security\Model\AdminSessionInfo::class);
        $this->auth->setAuthStorage($this->authSession);
        $this->messageManager = $this->objectManager->get(\Magento\Framework\Message\ManagerInterface::class);
        $this->adminSessionsManager = $this->objectManager->create(\Magento\Security\Model\AdminSessionsManager::class);
    }

    /**
     * Tear down
     */
    protected function tearDown(): void
    {
        $this->auth = null;
        $this->authSession  = null;
        $this->adminSessionInfo  = null;
        $this->adminSessionsManager = null;
        $this->objectManager = null;
        parent::tearDown();
    }

    /**
     * Test if current admin user is logged out
     *
     * @magentoDbIsolation enabled
     */
    public function testProcessLogout()
    {
        $this->auth->login(
            \Magento\TestFramework\Bootstrap::ADMIN_NAME,
            \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD
        );
        $adminSessionInfoId = $this->authSession->getAdminSessionInfoId();
        $this->auth->logout();
        $this->adminSessionInfo->load($adminSessionInfoId, 'id');
        $this->assertEquals($this->adminSessionInfo->getStatus(), AdminSessionInfo::LOGGED_OUT);
    }

    /**
     * Test if the admin session is created in database
     *
     * @magentoDbIsolation enabled
     */
    public function testIsAdminSessionIsCreated()
    {
        $this->auth->login(
            \Magento\TestFramework\Bootstrap::ADMIN_NAME,
            \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD
        );
        $adminSessionInfoId = $this->authSession->getAdminSessionInfoId();
        $this->adminSessionInfo->load($adminSessionInfoId, 'id');
        $this->assertGreaterThanOrEqual(1, (int)$this->adminSessionInfo->getId());
        $this->auth->logout();
    }

    /**
     * Test if other sessions are terminated if admin_account_sharing is disabled
     *
     * @magentoAdminConfigFixture admin/security/session_lifetime 100
     * @magentoConfigFixture default_store admin/security/admin_account_sharing 0
     * @magentoDbIsolation enabled
     */
    public function testTerminateOtherSessionsProcessLogin()
    {
        $session = $this->objectManager->create(\Magento\Security\Model\AdminSessionInfo::class);
        $session->setSessionId('669e2e3d752e8')
            ->setUserId(1)
            ->setStatus(1)
            ->setCreatedAt(time() - 10)
            ->setUpdatedAt(time() - 9)
            ->save();
        $this->auth->login(
            \Magento\TestFramework\Bootstrap::ADMIN_NAME,
            \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD
        );
        $adminSessionInfoId = $this->authSession->getAdminSessionInfoId();
        $session->load($adminSessionInfoId, 'id');
        $this->assertEquals(
            AdminSessionInfo::LOGGED_OUT_BY_LOGIN,
            (int) $session->getStatus()
        );
    }

    /**
     * Test if current session is retrieved
     *
     * @magentoDbIsolation enabled
     */
    public function testGetCurrentSession()
    {
        $this->auth->login(
            \Magento\TestFramework\Bootstrap::ADMIN_NAME,
            \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD
        );
        $adminSessionInfoId = $this->authSession->getAdminSessionInfoId();
        $this->adminSessionInfo->load($adminSessionInfoId, 'id');
        $this->assertEquals(
            $this->adminSessionInfo->getId(),
            $this->adminSessionsManager->getCurrentSession()->getId()
        );
    }

    /**
     * Test if other sessions were logged out if logoutOtherUserSessions() action was performed
     *
     * @magentoAdminConfigFixture admin/security/session_lifetime 100
     * @magentoConfigFixture default_store admin/security/admin_account_sharing 1
     * @magentoDbIsolation enabled
     */
    public function testLogoutOtherUserSessions()
    {
        /** @var \Magento\Security\Model\AdminSessionInfo $session */
        $session = $this->objectManager->create(\Magento\Security\Model\AdminSessionInfo::class);
        $session->setSessionId('669e2e3d752e8')
            ->setUserId(1)
            ->setStatus(1)
            ->setCreatedAt(time() - 50)
            ->setUpdatedAt(time() - 49)
            ->save();
        $this->auth->login(
            \Magento\TestFramework\Bootstrap::ADMIN_NAME,
            \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD
        );
        $collection = $this->getCollectionForLogoutOtherUserSessions($session);
        $this->assertGreaterThanOrEqual(1, $collection->getSize());
        $this->adminSessionsManager->logoutOtherUserSessions();
        $collection = $this->getCollectionForLogoutOtherUserSessions($session);
        $this->assertEquals(0, $collection->getSize());
    }

    /**
     * Collection getter with filters populated for testLogoutOtherUserSessions() method
     *
     * @param AdminSessionInfo $session
     * @return ResourceModel\AdminSessionInfo\Collection
     */
    protected function getCollectionForLogoutOtherUserSessions(\Magento\Security\Model\AdminSessionInfo $session)
    {
        /** @var \Magento\Security\Model\ResourceModel\AdminSessionInfo\Collection $collection */
        $collection = $session->getResourceCollection();
        $adminSessionInfoId = $this->authSession->getAdminSessionInfoId();
        $collection->filterByUser(
            $this->authSession->getUser()->getId(),
            \Magento\Security\Model\AdminSessionInfo::LOGGED_IN,
            $adminSessionInfoId
        )
            ->filterExpiredSessions(100)
            ->load();

        return $collection;
    }

    /**
     * Test for cleanExpiredSessions() method
     *
     * @magentoDataFixture Magento/Security/_files/adminsession.php
     * @magentoAdminConfigFixture admin/security/session_lifetime 1
     * @magentoDbIsolation enabled
     */
    public function testCleanExpiredSessions()
    {
        /** @var \Magento\Security\Model\AdminSessionInfo $session */
        $session = $this->objectManager->create(\Magento\Security\Model\AdminSessionInfo::class);
        $collection = $this->getCollectionForCleanExpiredSessions($session);
        $sizeBefore = $collection->getSize();
        $this->adminSessionsManager->cleanExpiredSessions();
        $collection = $this->getCollectionForCleanExpiredSessions($session);
        $sizeAfter = $collection->getSize();
        $this->assertGreaterThan($sizeAfter, $sizeBefore);
    }

    /**
     * Collection getter with filters populated for testCleanExpiredSessions() method
     *
     * @param AdminSessionInfo $session
     * @return ResourceModel\AdminSessionInfo\Collection
     */
    protected function getCollectionForCleanExpiredSessions(\Magento\Security\Model\AdminSessionInfo $session)
    {
        /** @var \Magento\Security\Model\ResourceModel\AdminSessionInfo\Collection $collection */
        $collection = $session->getResourceCollection()
            ->load();

        return $collection;
    }
}
