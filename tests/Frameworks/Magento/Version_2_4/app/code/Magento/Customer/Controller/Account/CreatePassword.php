<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Customer\Controller\Account;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Model\ForgotPasswordToken\ConfirmCustomerByToken;
use Magento\Customer\Model\ForgotPasswordToken\GetCustomerByToken;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;

/**
 * Controller for front-end customer password reset form
 */
class CreatePassword extends \Magento\Customer\Controller\AbstractAccount implements HttpGetActionInterface
{
    /**
     * @var AccountManagementInterface
     */
    protected $accountManagement;

    /**
     * @var Session
     */
    protected $session;

    /**
     * @var PageFactory
     */
    protected $resultPageFactory;

    /**
     * @var ConfirmCustomerByToken
     */
    private $confirmByToken;

    /**
     * @var GetCustomerByToken
     */
    private $getByToken;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @param Context $context
     * @param Session $customerSession
     * @param PageFactory $resultPageFactory
     * @param AccountManagementInterface $accountManagement
     * @param ConfirmCustomerByToken|null $confirmByToken
     * @param GetCustomerByToken|null $getByToken
     * @param CustomerRepositoryInterface|null $customerRepository
     */
    public function __construct(
        Context $context,
        Session $customerSession,
        PageFactory $resultPageFactory,
        AccountManagementInterface $accountManagement,
        ConfirmCustomerByToken $confirmByToken = null,
        GetCustomerByToken $getByToken = null,
        CustomerRepositoryInterface $customerRepository = null
    ) {
        $this->session = $customerSession;
        $this->resultPageFactory = $resultPageFactory;
        $this->accountManagement = $accountManagement;
        $this->confirmByToken = $confirmByToken
            ?? ObjectManager::getInstance()->get(ConfirmCustomerByToken::class);
        $this->getByToken = $getByToken
            ?? ObjectManager::getInstance()->get(GetCustomerByToken::class);
        $this->customerRepository = $customerRepository
            ?? ObjectManager::getInstance()->get(CustomerRepositoryInterface::class);

        parent::__construct($context);
    }

    /**
     * Resetting password handler
     *
     * @return Redirect|Page
     */
    public function execute()
    {
        $resetPasswordToken = (string)$this->getRequest()->getParam('token');
        $customerId = (int)$this->getRequest()->getParam('id');
        $isDirectLink = $resetPasswordToken != '';
        if (!$isDirectLink) {
            $resetPasswordToken = (string)$this->session->getRpToken();
            $customerId = (int)$this->session->getRpCustomerId();
        }

        try {
            $this->accountManagement->validateResetPasswordLinkToken($customerId, $resetPasswordToken);
            $this->confirmByToken->resetCustomerConfirmation($customerId);

            // Extend token validity to avoid expiration while this form is
            // being completed by the user.
            $customer = $this->customerRepository->getById($customerId);
            $this->accountManagement->changeResetPasswordLinkToken($customer, $resetPasswordToken);

            if ($isDirectLink) {
                $this->session->setRpToken($resetPasswordToken);
                $this->session->setRpCustomerId($customerId);
                $resultRedirect = $this->resultRedirectFactory->create();
                $resultRedirect->setPath('*/*/createpassword');

                return $resultRedirect;
            } else {
                /** @var Page $resultPage */
                $resultPage = $this->resultPageFactory->create();
                $resultPage->getLayout()
                           ->getBlock('resetPassword')
                           ->setResetPasswordLinkToken($resetPasswordToken)
                           ->setRpCustomerId($customerId);

                return $resultPage;
            }
        } catch (\Exception $exception) {
            $this->messageManager->addErrorMessage(__('Your password reset link has expired.'));
            /** @var Redirect $resultRedirect */
            $resultRedirect = $this->resultRedirectFactory->create();
            $resultRedirect->setPath('*/*/forgotpassword');

            return $resultRedirect;
        }
    }
}
