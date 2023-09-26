<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Customer\Model;

use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Framework\App\RequestInterface;

/**
 * Customer Extractor model.
 */
class CustomerExtractor
{
    /**
     * @var \Magento\Customer\Model\Metadata\FormFactory
     */
    protected $formFactory;

    /**
     * @var \Magento\Customer\Api\Data\CustomerInterfaceFactory
     */
    protected $customerFactory;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var GroupManagementInterface
     */
    protected $customerGroupManagement;

    /**
     * @var \Magento\Framework\Api\DataObjectHelper
     */
    protected $dataObjectHelper;

    /**
     * @param Metadata\FormFactory $formFactory
     * @param \Magento\Customer\Api\Data\CustomerInterfaceFactory $customerFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param GroupManagementInterface $customerGroupManagement
     * @param \Magento\Framework\Api\DataObjectHelper $dataObjectHelper
     */
    public function __construct(
        \Magento\Customer\Model\Metadata\FormFactory $formFactory,
        \Magento\Customer\Api\Data\CustomerInterfaceFactory $customerFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        GroupManagementInterface $customerGroupManagement,
        \Magento\Framework\Api\DataObjectHelper $dataObjectHelper
    ) {
        $this->formFactory = $formFactory;
        $this->customerFactory = $customerFactory;
        $this->storeManager = $storeManager;
        $this->customerGroupManagement = $customerGroupManagement;
        $this->dataObjectHelper = $dataObjectHelper;
    }

    /**
     * Creates a Customer object populated with the given form code and request data.
     *
     * @param string $formCode
     * @param RequestInterface $request
     * @param array $attributeValues
     * @return CustomerInterface
     */
    public function extract(
        $formCode,
        RequestInterface $request,
        array $attributeValues = []
    ) {
        $customerForm = $this->formFactory->create(
            CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
            $formCode,
            $attributeValues
        );

        $customerData = $customerForm->extractData($request);
        $customerData = $customerForm->compactData($customerData);

        $allowedAttributes = $customerForm->getAllowedAttributes();
        $isGroupIdEmpty = !isset($allowedAttributes['group_id']);

        $customerDataObject = $this->customerFactory->create();
        $this->dataObjectHelper->populateWithArray(
            $customerDataObject,
            $customerData,
            \Magento\Customer\Api\Data\CustomerInterface::class
        );

        $store = $this->storeManager->getStore();
        $storeId = $store->getId();

        if ($isGroupIdEmpty) {
            $groupId = isset($customerData['group_id']) ? $customerData['group_id']
                : $this->customerGroupManagement->getDefaultGroup($storeId)->getId();
            $customerDataObject->setGroupId(
                $groupId
            );
        }

        $customerDataObject->setWebsiteId($store->getWebsiteId());
        $customerDataObject->setStoreId($storeId);

        return $customerDataObject;
    }
}
