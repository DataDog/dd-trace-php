<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Model;

use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\AuthenticationException;

/**
 * Test class for \Magento\Backend\Model\Auth.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class AuthTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Backend\Model\Auth
     */
    protected $_model;

    protected function setUp(): void
    {
        parent::setUp();

        \Magento\TestFramework\Helper\Bootstrap::getInstance()
            ->loadArea(\Magento\Backend\App\Area\FrontNameResolver::AREA_CODE);
        $this->_model = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->create(\Magento\Backend\Model\Auth::class);
    }

    /**
     * @dataProvider getLoginDataProvider
     * @param string $userName
     * @param string $password
     *
     */
    public function testLoginFailed($userName, $password)
    {
        $this->expectException(\Magento\Framework\Exception\AuthenticationException::class);
        $this->_model->login($userName, $password);
    }

    public function getLoginDataProvider()
    {
        return [
            'Invalid credentials' => ['not_exists', 'not_exists'],
            'Empty credentials' => ['', 'not_exists']
        ];
    }

    public function testSetGetAuthStorage()
    {
        // by default \Magento\Backend\Model\Auth\Session class will instantiate as a Authentication Storage
        $this->assertInstanceOf(\Magento\Backend\Model\Auth\Session::class, $this->_model->getAuthStorage());

        $mockStorage = $this->createMock(\Magento\Backend\Model\Auth\StorageInterface::class);
        $this->_model->setAuthStorage($mockStorage);
        $this->assertInstanceOf(\Magento\Backend\Model\Auth\StorageInterface::class, $this->_model->getAuthStorage());

        $incorrectStorage = new \StdClass();
        try {
            $this->_model->setAuthStorage($incorrectStorage);
            $this->fail('Incorrect authentication storage setted.');
        } catch (AuthenticationException $e) {
            // in case of exception - Auth works correct
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function testGetCredentialStorageList()
    {
        $storage = $this->_model->getCredentialStorage();
        $this->assertInstanceOf(\Magento\Backend\Model\Auth\Credential\StorageInterface::class, $storage);
    }

    public function testLoginSuccessful()
    {
        $this->_model->login(
            \Magento\TestFramework\Bootstrap::ADMIN_NAME,
            \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD
        );
        $this->assertInstanceOf(
            \Magento\Backend\Model\Auth\Credential\StorageInterface::class,
            $this->_model->getUser()
        );
        $this->assertGreaterThan(time() - 10, $this->_model->getAuthStorage()->getUpdatedAt());
    }

    public function testLoginFlushesFormKey()
    {
        /** @var FormKey $dataFormKey */
        $dataFormKey = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(FormKey::class);
        $beforeKey = $dataFormKey->getFormKey();
        $this->_model->login(
            \Magento\TestFramework\Bootstrap::ADMIN_NAME,
            \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD
        );
        $afterKey = $dataFormKey->getFormKey();
        $this->assertNotEquals($beforeKey, $afterKey);
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testLogout()
    {
        $this->_model->login(
            \Magento\TestFramework\Bootstrap::ADMIN_NAME,
            \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD
        );
        $this->assertNotEmpty($this->_model->getAuthStorage()->getData());
        $this->_model->logout();
        $this->assertEmpty($this->_model->getAuthStorage()->getData());
    }

    /**
     * Disabled form security in order to prevent exit from the app
     * @magentoAdminConfigFixture admin/security/session_lifetime 100
     */
    public function testIsLoggedIn()
    {
        $this->_model->login(
            \Magento\TestFramework\Bootstrap::ADMIN_NAME,
            \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD
        );
        $this->assertTrue($this->_model->isLoggedIn());
    }

    public function testGetUser()
    {
        $this->_model->login(
            \Magento\TestFramework\Bootstrap::ADMIN_NAME,
            \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD
        );

        $this->assertNotNull($this->_model->getUser());
        $this->assertGreaterThan(0, $this->_model->getUser()->getId());
        $this->assertInstanceOf(
            \Magento\Backend\Model\Auth\Credential\StorageInterface::class,
            $this->_model->getUser()
        );
    }
}
