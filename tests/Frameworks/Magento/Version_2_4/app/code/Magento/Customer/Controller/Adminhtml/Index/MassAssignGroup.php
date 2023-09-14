<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Controller\Adminhtml\Index;

use Magento\Customer\Model\Customer;
use Magento\Framework\App\Action\HttpPostActionInterface as HttpPostActionInterface;
use Magento\Backend\App\Action\Context;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory;
use Magento\Eav\Model\Entity\Collection\AbstractCollection;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * Class MassAssignGroup
 */
class MassAssignGroup extends AbstractMassAction implements HttpPostActionInterface
{
    /**
     * @var CustomerRepositoryInterface
     */
    protected $customerRepository;

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        CustomerRepositoryInterface $customerRepository
    ) {
        parent::__construct($context, $filter, $collectionFactory);
        $this->customerRepository = $customerRepository;
    }

    /**
     * Customer mass assign group action
     *
     * @param AbstractCollection $collection
     * @return \Magento\Backend\Model\View\Result\Redirect
     */
    protected function massAction(AbstractCollection $collection)
    {
        $customersUpdated = 0;
        foreach ($collection->getAllIds() as $customerId) {
            // Verify customer exists
            $customer = $this->customerRepository->getById($customerId);
            $customer->setGroupId($this->getRequest()->getParam('group'));
            // No need to validate customer and customer address during assigning customer to the group
            $this->setIgnoreValidationFlag($customer);
            $this->customerRepository->save($customer);
            $customersUpdated++;
        }

        if ($customersUpdated) {
            $this->messageManager->addSuccessMessage(__('A total of %1 record(s) were updated.', $customersUpdated));
        }
        /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath($this->getComponentRefererUrl());

        return $resultRedirect;
    }

    /**
     * Set ignore_validation_flag to skip unnecessary address and customer validation
     *
     * @param Customer $customer
     * @return void
     */
    private function setIgnoreValidationFlag($customer)
    {
        $customer->setData('ignore_validation_flag', true);
    }
}
