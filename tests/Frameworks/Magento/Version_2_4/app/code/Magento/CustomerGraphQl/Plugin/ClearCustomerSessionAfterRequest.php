<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CustomerGraphQl\Plugin;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Model\ResourceModel\CustomerRepository;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\CustomerGraphQl\Model\Context\AddUserInfoToContext;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\GraphQl\Controller\GraphQl as GraphQlController;

/**
 * Clear the user data out of the session object before returning the GraphQL response
 *
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 */
class ClearCustomerSessionAfterRequest
{
    /**
     * @var UserContextInterface
     */
    private $userContext;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var CustomerRepository
     */
    private $customerRepository;

    /**
     * @var AddUserInfoToContext
     */
    private $addUserInfoToContext;

    /**
     * @param UserContextInterface $userContext
     * @param CustomerSession $customerSession
     * @param CustomerRepository $customerRepository
     * @param AddUserInfoToContext $addUserInfoToContext
     */
    public function __construct(
        UserContextInterface $userContext,
        CustomerSession $customerSession,
        CustomerRepository $customerRepository,
        AddUserInfoToContext $addUserInfoToContext = null
    ) {
        $this->userContext = $userContext;
        $this->customerSession = $customerSession;
        $this->customerRepository = $customerRepository;
        $this->addUserInfoToContext = $addUserInfoToContext ??
            ObjectManager::getInstance()->get(AddUserInfoToContext::class);
    }

    /**
     * Clear the customer data from the session after business logic has completed
     *
     * @param GraphQlController $controller
     * @param ResponseInterface $response
     * @return ResponseInterface
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterDispatch(GraphQlController $controller, ResponseInterface $response): ResponseInterface
    {
        $loggedInCustomerData = $this->addUserInfoToContext->getLoggedInCustomerData();
        $this->customerSession->setCustomerId($loggedInCustomerData ? $loggedInCustomerData->getId() : null);
        $this->customerSession->setCustomerGroupId($loggedInCustomerData ? $loggedInCustomerData->getGroupId() : null);

        return $response;
    }
}
