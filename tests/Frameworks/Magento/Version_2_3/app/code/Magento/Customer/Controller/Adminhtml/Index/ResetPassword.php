<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Controller\Adminhtml\Index;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\SecurityViolationException;

/**
 * Reset password controller
 *
 * @package Magento\Customer\Controller\Adminhtml\Index
 */
class ResetPassword extends \Magento\Customer\Controller\Adminhtml\Index implements HttpGetActionInterface
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Magento_Customer::reset_password';

    /**
     * Reset password handler
     *
     * @return \Magento\Backend\Model\View\Result\Redirect
     */
    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $customerId = (int)$this->getRequest()->getParam('customer_id', 0);
        if (!$customerId) {
            $resultRedirect->setPath('customer/index');
            return $resultRedirect;
        }

        try {
            $customer = $this->_customerRepository->getById($customerId);
            $this->customerAccountManagement->initiatePasswordReset(
                $customer->getEmail(),
                \Magento\Customer\Model\AccountManagement::EMAIL_REMINDER,
                $customer->getWebsiteId()
            );
            $this->messageManager->addSuccessMessage(
                __('The customer will receive an email with a link to reset password.')
            );
        } catch (NoSuchEntityException $exception) {
            $resultRedirect->setPath('customer/index');
            return $resultRedirect;
        } catch (\Magento\Framework\Validator\Exception $exception) {
            $messages = $exception->getMessages(\Magento\Framework\Message\MessageInterface::TYPE_ERROR);
            if (!count($messages)) {
                $messages = $exception->getMessage();
            }
            $this->_addSessionErrorMessages($messages);
        } catch (SecurityViolationException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Exception $exception) {
            $this->messageManager->addExceptionMessage(
                $exception,
                __('Something went wrong while resetting customer password.')
            );
        }
        $resultRedirect->setPath(
            'customer/*/edit',
            ['id' => $customerId, '_current' => true]
        );
        return $resultRedirect;
    }
}
