<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Integration\Test\Unit\Controller\Adminhtml\Integration;

use Magento\Integration\Block\Adminhtml\Integration\Edit\Tab\Info;
use Magento\Integration\Controller\Adminhtml\Integration as IntegrationController;
use Magento\Integration\Model\Integration as IntegrationModel;
use Magento\Framework\Exception\IntegrationException;
use Magento\Framework\Exception\State\UserLockedException;
use Magento\Framework\Exception\AuthenticationException;

class SaveTest extends \Magento\Integration\Test\Unit\Controller\Adminhtml\IntegrationTest
{
    public function testSaveAction()
    {
        // Use real translate model
        $this->_translateModelMock = null;
        $this->_requestMock->expects($this->any())
            ->method('getPostValue')
            ->willReturn([IntegrationController::PARAM_INTEGRATION_ID => self::INTEGRATION_ID]);
        $this->_requestMock->expects($this->any())->method('getParam')->willReturn(self::INTEGRATION_ID);
        $intData = $this->_getSampleIntegrationData();
        $this->_integrationSvcMock->expects($this->any())
            ->method('get')
            ->with(self::INTEGRATION_ID)
            ->willReturn($intData);
        $this->_integrationSvcMock->expects($this->any())
            ->method('update')
            ->with($this->anything())
            ->willReturn($intData);
        // verify success message
        $this->_messageManager->expects($this->once())
            ->method('addSuccess')
            ->with(__('The integration \'%1\' has been saved.', $intData[Info::DATA_NAME]));

        $this->_escaper->expects($this->once())
            ->method('escapeHtml')
            ->willReturnArgument(0);

        $integrationContr = $this->_createIntegrationController('Save');
        $integrationContr->execute();
    }

    public function testSaveActionException()
    {
        $this->_requestMock->expects($this->any())->method('getParam')->willReturn(self::INTEGRATION_ID);

        // Have integration service throw an exception to test exception path
        $exceptionMessage = 'Internal error. Check exception log for details.';
        $this->_integrationSvcMock->expects($this->any())
            ->method('get')
            ->with(self::INTEGRATION_ID)
            ->will($this->throwException(new \Magento\Framework\Exception\LocalizedException(__($exceptionMessage))));
        // Verify error
        $this->_messageManager->expects($this->once())->method('addError')->with($this->equalTo($exceptionMessage));
        $integrationContr = $this->_createIntegrationController('Save');
        $integrationContr->execute();
    }

    public function testSaveActionIntegrationException()
    {
        $this->_requestMock->expects($this->any())->method('getParam')->willReturn(self::INTEGRATION_ID);

        // Have integration service throw an exception to test exception path
        $exceptionMessage = 'Internal error. Check exception log for details.';
        $this->_integrationSvcMock->expects(
            $this->any()
        )->method(
            'get'
        )->with(
            self::INTEGRATION_ID
        )->will(
            $this->throwException(new IntegrationException(__($exceptionMessage)))
        );

        $this->_escaper->expects($this->once())
            ->method('escapeHtml')
            ->willReturnArgument(0);
        // Verify error
        $this->_messageManager->expects($this->once())->method('addError')->with($this->equalTo($exceptionMessage));
        $integrationContr = $this->_createIntegrationController('Save');
        $integrationContr->execute();
    }

    public function testSaveActionNew()
    {
        $integration = $this->_getSampleIntegrationData();
        //No id when New Integration is Post-ed
        $integration->unsetData([IntegrationModel::ID, 'id']);
        $this->_requestMock->expects(
            $this->any()
        )->method(
            'getPostValue'
        )->willReturn(
            $integration->getData()
        );
        $integration->setData('id', self::INTEGRATION_ID);
        $this->_integrationSvcMock->expects(
            $this->any()
        )->method(
            'create'
        )->with(
            $this->anything()
        )->willReturn(
            $integration
        );
        $this->_integrationSvcMock->expects(
            $this->any()
        )->method(
            'get'
        )->with(
            self::INTEGRATION_ID
        )->willReturn(
            null
        );
        // Use real translate model
        $this->_translateModelMock = null;
        // verify success message
        $this->_messageManager->expects(
            $this->once()
        )->method(
            'addSuccess'
        )->with(
            __('The integration \'%1\' has been saved.', $integration->getName())
        );

        $this->_escaper->expects($this->once())
            ->method('escapeHtml')
            ->willReturnArgument(0);
        $integrationContr = $this->_createIntegrationController('Save');
        $integrationContr->execute();
    }

    public function testSaveActionExceptionDuringServiceCreation()
    {
        $exceptionMessage = 'Service could not be saved.';
        $integration = $this->_getSampleIntegrationData();
        // No id when New Integration is Post-ed
        $integration->unsetData([IntegrationModel::ID, 'id']);
        $this->_requestMock->expects(
            $this->any()
        )->method(
            'getPostValue'
        )->willReturn(
            $integration->getData()
        );
        $integration->setData('id', self::INTEGRATION_ID);
        $this->_integrationSvcMock->expects(
            $this->any()
        )->method(
            'create'
        )->with(
            $this->anything()
        )->will(
            $this->throwException(new IntegrationException(__($exceptionMessage)))
        );
        $this->_integrationSvcMock->expects(
            $this->any()
        )->method(
            'get'
        )->with(
            self::INTEGRATION_ID
        )->willReturn(
            null
        );

        $this->_escaper->expects($this->once())
            ->method('escapeHtml')
            ->willReturnArgument(0);
        // Use real translate model
        $this->_translateModelMock = null;
        // Verify success message
        $this->_messageManager->expects($this->once())->method('addError')->with($exceptionMessage);
        $integrationController = $this->_createIntegrationController('Save');
        $integrationController->execute();
    }

    public function testSaveActionExceptionOnIntegrationsCreatedFromConfigFile()
    {
        $exceptionMessage = "The integrations created in the config file can't be edited.";
        $intData = new \Magento\Framework\DataObject(
            [
                Info::DATA_NAME => 'nameTest',
                Info::DATA_ID => self::INTEGRATION_ID,
                'id' => self::INTEGRATION_ID,
                Info::DATA_EMAIL => 'test@magento.com',
                Info::DATA_ENDPOINT => 'http://magento.ll/endpoint',
                Info::DATA_SETUP_TYPE => IntegrationModel::TYPE_CONFIG,
            ]
        );

        $this->_requestMock->expects($this->any())->method('getParam')->willReturn(self::INTEGRATION_ID);
        $this->_integrationSvcMock
            ->expects($this->once())
            ->method('get')
            ->with(self::INTEGRATION_ID)
            ->willReturn($intData);

        $this->_escaper->expects($this->once())
            ->method('escapeHtml')
            ->willReturnArgument(0);

        // Verify error
        $this->_messageManager->expects($this->once())->method('addError')->with($this->equalTo($exceptionMessage));
        $integrationContr = $this->_createIntegrationController('Save');
        $integrationContr->execute();
    }

    /**
     * @return void
     */
    public function testSaveActionUserLockedException()
    {
        $exceptionMessage = __('Your account is temporarily disabled. Please try again later.');
        $passwordString = '1234567';

        $this->_requestMock->expects($this->exactly(2))
            ->method('getParam')
            ->withConsecutive(
                [\Magento\Integration\Controller\Adminhtml\Integration\Save::PARAM_INTEGRATION_ID],
                [\Magento\Integration\Block\Adminhtml\Integration\Edit\Tab\Info::DATA_CONSUMER_PASSWORD]
            )
            ->willReturnOnConsecutiveCalls(self::INTEGRATION_ID, $passwordString);

        $intData = $this->_getSampleIntegrationData();
        $this->_integrationSvcMock->expects($this->once())
            ->method('get')
            ->with(self::INTEGRATION_ID)
            ->willReturn($intData);

        $this->_userMock->expects($this->any())
            ->method('performIdentityCheck')
            ->with($passwordString)
            ->will($this->throwException(new UserLockedException(__($exceptionMessage))));

        $this->_authMock->expects($this->once())
            ->method('logout');

        $this->securityCookieMock->expects($this->once())
            ->method('setLogoutReasonCookie')
            ->with(\Magento\Security\Model\AdminSessionsManager::LOGOUT_REASON_USER_LOCKED);

        $integrationContr = $this->_createIntegrationController('Save');
        $integrationContr->execute();
    }

    /**
     * @return void
     */
    public function testSaveActionAuthenticationException()
    {
        $passwordString = '1234567';
        $exceptionMessage =
            __('The password entered for the current user is invalid. Verify the password and try again.');

        $this->_requestMock->expects($this->any())
            ->method('getParam')
            ->withConsecutive(
                [\Magento\Integration\Controller\Adminhtml\Integration\Save::PARAM_INTEGRATION_ID],
                [\Magento\Integration\Block\Adminhtml\Integration\Edit\Tab\Info::DATA_CONSUMER_PASSWORD]
            )
            ->willReturnOnConsecutiveCalls(self::INTEGRATION_ID, $passwordString);

        $intData = $this->_getSampleIntegrationData();
        $this->_integrationSvcMock->expects($this->once())
            ->method('get')
            ->with(self::INTEGRATION_ID)
            ->willReturn($intData);

        $this->_userMock->expects($this->any())
            ->method('performIdentityCheck')
            ->with($passwordString)
            ->will($this->throwException(new AuthenticationException(__($exceptionMessage))));

        // Verify error
        $this->_messageManager->expects($this->once())->method('addError')->with($this->equalTo($exceptionMessage));
        $integrationContr = $this->_createIntegrationController('Save');
        $integrationContr->execute();
    }
}
