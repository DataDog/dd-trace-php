<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Customer\Model\ResourceModel;

use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerSearchResultsInterfaceFactory;
use Magento\Customer\Model\Customer as CustomerModel;
use Magento\Customer\Model\Customer\Attribute\CompositeValidator;
use Magento\Customer\Model\Customer\NotificationStorage;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Customer\Model\Data\CustomerSecureFactory;
use Magento\Customer\Model\Delegation\Data\NewOperation;
use Magento\Customer\Model\Delegation\Storage as DelegatedStorage;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\Api\ExtensibleDataObjectConverter;
use Magento\Framework\Api\ExtensionAttribute\JoinProcessorInterface;
use Magento\Framework\Api\ImageProcessorInterface;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Event\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Model\ResourceModel\Customer\Collection;

/**
 * Customer repository responsible for CRUD operations.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class CustomerRepository implements CustomerRepositoryInterface
{
    /**
     * @var CustomerFactory
     */
    protected $customerFactory;

    /**
     * @var CustomerSecureFactory
     */
    protected $customerSecureFactory;

    /**
     * @var CustomerRegistry
     */
    protected $customerRegistry;

    /**
     * @var AddressRepository
     */
    protected $addressRepository;

    /**
     * @var Customer
     */
    protected $customerResourceModel;

    /**
     * @var CustomerMetadataInterface
     */
    protected $customerMetadata;

    /**
     * @var CustomerSearchResultsInterfaceFactory
     */
    protected $searchResultsFactory;

    /**
     * @var ManagerInterface
     */
    protected $eventManager;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var ExtensibleDataObjectConverter
     */
    protected $extensibleDataObjectConverter;

    /**
     * @var DataObjectHelper
     */
    protected $dataObjectHelper;

    /**
     * @var ImageProcessorInterface
     */
    protected $imageProcessor;

    /**
     * @var JoinProcessorInterface
     */
    protected $extensionAttributesJoinProcessor;

    /**
     * @var CollectionProcessorInterface
     */
    private $collectionProcessor;

    /**
     * @var NotificationStorage
     */
    private $notificationStorage;

    /**
     * @var DelegatedStorage
     */
    private $delegatedStorage;

    /**
     * @var CompositeValidator
     */
    private $compositeValidator;

    /**
     * @param CustomerFactory $customerFactory
     * @param CustomerSecureFactory $customerSecureFactory
     * @param CustomerRegistry $customerRegistry
     * @param AddressRepository $addressRepository
     * @param Customer $customerResourceModel
     * @param CustomerMetadataInterface $customerMetadata
     * @param CustomerSearchResultsInterfaceFactory $searchResultsFactory
     * @param ManagerInterface $eventManager
     * @param StoreManagerInterface $storeManager
     * @param ExtensibleDataObjectConverter $extensibleDataObjectConverter
     * @param DataObjectHelper $dataObjectHelper
     * @param ImageProcessorInterface $imageProcessor
     * @param JoinProcessorInterface $extensionAttributesJoinProcessor
     * @param CollectionProcessorInterface $collectionProcessor
     * @param NotificationStorage $notificationStorage
     * @param DelegatedStorage|null $delegatedStorage
     * @param CompositeValidator|null $compositeValidator
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        CustomerFactory $customerFactory,
        CustomerSecureFactory $customerSecureFactory,
        CustomerRegistry $customerRegistry,
        AddressRepository $addressRepository,
        Customer $customerResourceModel,
        CustomerMetadataInterface $customerMetadata,
        CustomerSearchResultsInterfaceFactory $searchResultsFactory,
        ManagerInterface $eventManager,
        StoreManagerInterface $storeManager,
        ExtensibleDataObjectConverter $extensibleDataObjectConverter,
        DataObjectHelper $dataObjectHelper,
        ImageProcessorInterface $imageProcessor,
        JoinProcessorInterface $extensionAttributesJoinProcessor,
        CollectionProcessorInterface $collectionProcessor,
        NotificationStorage $notificationStorage,
        DelegatedStorage $delegatedStorage = null,
        CompositeValidator $compositeValidator = null
    ) {
        $this->customerFactory = $customerFactory;
        $this->customerSecureFactory = $customerSecureFactory;
        $this->customerRegistry = $customerRegistry;
        $this->addressRepository = $addressRepository;
        $this->customerResourceModel = $customerResourceModel;
        $this->customerMetadata = $customerMetadata;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->eventManager = $eventManager;
        $this->storeManager = $storeManager;
        $this->extensibleDataObjectConverter = $extensibleDataObjectConverter;
        $this->dataObjectHelper = $dataObjectHelper;
        $this->imageProcessor = $imageProcessor;
        $this->extensionAttributesJoinProcessor = $extensionAttributesJoinProcessor;
        $this->collectionProcessor = $collectionProcessor;
        $this->notificationStorage = $notificationStorage;
        $this->delegatedStorage = $delegatedStorage ?? ObjectManager::getInstance()->get(DelegatedStorage::class);
        $this->compositeValidator = $compositeValidator ?? ObjectManager::getInstance()->get(
            CompositeValidator::class
        );
    }

    /**
     * Create or update a customer.
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface $customer
     * @param string $passwordHash
     * @return \Magento\Customer\Api\Data\CustomerInterface
     * @throws \Magento\Framework\Exception\InputException If bad input is provided
     * @throws \Magento\Framework\Exception\State\InputMismatchException If the provided email is already used
     * @throws \Magento\Framework\Exception\LocalizedException
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function save(CustomerInterface $customer, $passwordHash = null)
    {
        foreach ($customer->getCustomAttributes() as $customAttribute) {
            $this->compositeValidator->validate($customAttribute);
        }
        /** @var NewOperation|null $delegatedNewOperation */
        $delegatedNewOperation = !$customer->getId() ? $this->delegatedStorage->consumeNewOperation() : null;
        $prevCustomerData = null;
        $prevCustomerDataArr = null;
        if ($customer->getId()) {
            $prevCustomerData = $this->getById($customer->getId());
            $prevCustomerDataArr = $prevCustomerData->__toArray();
        }
        /** @var $customer \Magento\Customer\Model\Data\Customer */
        $customerArr = $customer->__toArray();
        $customer = $this->imageProcessor->save(
            $customer,
            CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
            $prevCustomerData
        );
        $origAddresses = $customer->getAddresses();
        $customer->setAddresses([]);
        $customerData = $this->extensibleDataObjectConverter->toNestedArray($customer, [], CustomerInterface::class);
        $customer->setAddresses($origAddresses);
        /** @var CustomerModel $customerModel */
        $customerModel = $this->customerFactory->create(['data' => $customerData]);
        //Model's actual ID field maybe different than "id" so "id" field from $customerData may be ignored.
        $customerModel->setId($customer->getId());
        $storeId = $customerModel->getStoreId();
        if ($storeId === null) {
            $customerModel->setStoreId(
                $prevCustomerData ? $prevCustomerData->getStoreId() : $this->storeManager->getStore()->getId()
            );
        }
        // Need to use attribute set or future updates can cause data loss
        if (!$customerModel->getAttributeSetId()) {
            $customerModel->setAttributeSetId(CustomerMetadataInterface::ATTRIBUTE_SET_ID_CUSTOMER);
        }
        $this->populateCustomerWithSecureData($customerModel, $passwordHash);
        // If customer email was changed, reset RpToken info
        if ($prevCustomerData && $prevCustomerData->getEmail() !== $customerModel->getEmail()) {
            $customerModel->setRpToken(null);
            $customerModel->setRpTokenCreatedAt(null);
        }
        if ($this->isDefaultAddressChanged('default_billing', $customerArr, $prevCustomerDataArr)) {
            $customerModel->setDefaultBilling($prevCustomerDataArr['default_billing']);
        }
        if ($this->isDefaultAddressChanged('default_shipping', $customerArr, $prevCustomerDataArr)) {
            $customerModel->setDefaultShipping($prevCustomerDataArr['default_shipping']);
        }
        $this->setValidationFlag($customerArr, $customerModel);
        $customerModel->save();
        $this->customerRegistry->push($customerModel);
        $customerId = $customerModel->getId();
        if (!$customer->getAddresses()
            && $delegatedNewOperation
            && $delegatedNewOperation->getCustomer()->getAddresses()
        ) {
            $customer->setAddresses($delegatedNewOperation->getCustomer()->getAddresses());
        }
        if ($customer->getAddresses() !== null && !$customerModel->getData('ignore_validation_flag')) {
            if ($customer->getId()) {
                $existingAddresses = $this->getById($customer->getId())->getAddresses();
                $getIdFunc = function ($address) {
                    return $address->getId();
                };
                $existingAddressIds = array_map($getIdFunc, $existingAddresses);
            } else {
                $existingAddressIds = [];
            }
            $savedAddressIds = [];
            foreach ($customer->getAddresses() as $address) {
                $address->setCustomerId($customerId)
                    ->setRegion($address->getRegion());
                $this->addressRepository->save($address);
                if ($address->getId()) {
                    $savedAddressIds[] = $address->getId();
                }
            }
            $addressIdsToDelete = array_diff($existingAddressIds, $savedAddressIds);
            foreach ($addressIdsToDelete as $addressId) {
                $this->addressRepository->deleteById($addressId);
            }
        }
        $this->customerRegistry->remove($customerId);
        $savedCustomer = $this->get($customer->getEmail(), $customer->getWebsiteId());
        $this->eventManager->dispatch(
            'customer_save_after_data_object',
            [
                'customer_data_object' => $savedCustomer,
                'orig_customer_data_object' => $prevCustomerData,
                'delegate_data' => $delegatedNewOperation ? $delegatedNewOperation->getAdditionalData() : [],
            ]
        );
        return $savedCustomer;
    }

    /**
     * Check if customer default address is changed.
     *
     * @param string $addressType
     * @param array $currentCustomerData
     * @param array|null $prevCustomerData
     *
     * @return bool
     */
    private function isDefaultAddressChanged(
        string $addressType,
        array $currentCustomerData,
        ?array $prevCustomerData
    ): bool {
        return !array_key_exists('addresses', $currentCustomerData)
            && null !== $prevCustomerData
            && array_key_exists($addressType, $prevCustomerData);
    }

    /**
     * Set secure data to customer model
     *
     * @param \Magento\Customer\Model\Customer $customerModel
     * @param string|null $passwordHash
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @return void
     */
    private function populateCustomerWithSecureData($customerModel, $passwordHash = null)
    {
        if ($customerModel->getId()) {
            $customerSecure = $this->customerRegistry->retrieveSecureData($customerModel->getId());

            $customerModel->setRpToken($passwordHash ? null : $customerSecure->getRpToken());
            $customerModel->setRpTokenCreatedAt($passwordHash ? null : $customerSecure->getRpTokenCreatedAt());
            $customerModel->setPasswordHash($passwordHash ?: $customerSecure->getPasswordHash());

            $customerModel->setFailuresNum($customerSecure->getFailuresNum());
            $customerModel->setFirstFailure($customerSecure->getFirstFailure());
            $customerModel->setLockExpires($customerSecure->getLockExpires());
        } elseif ($passwordHash) {
            $customerModel->setPasswordHash($passwordHash);
        }

        if ($passwordHash && $customerModel->getId()) {
            $this->customerRegistry->remove($customerModel->getId());
        }
    }

    /**
     * Retrieve customer.
     *
     * @param string $email
     * @param int|null $websiteId
     * @return \Magento\Customer\Api\Data\CustomerInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException If customer with the specified email does not exist.
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function get($email, $websiteId = null)
    {
        $customerModel = $this->customerRegistry->retrieveByEmail($email, $websiteId);
        return $customerModel->getDataModel();
    }

    /**
     * Get customer by Customer ID.
     *
     * @param int $customerId
     * @return \Magento\Customer\Api\Data\CustomerInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException If customer with the specified ID does not exist.
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getById($customerId)
    {
        $customerModel = $this->customerRegistry->retrieve($customerId);
        return $customerModel->getDataModel();
    }

    /**
     * Retrieve customers which match a specified criteria.
     *
     * This call returns an array of objects, but detailed information about each object’s attributes might not be
     * included. See https://devdocs.magento.com/codelinks/attributes.html#CustomerRepositoryInterface to determine
     * which call to use to get detailed information about all attributes for an object.
     *
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \Magento\Customer\Api\Data\CustomerSearchResultsInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getList(SearchCriteriaInterface $searchCriteria)
    {
        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        /** @var \Magento\Customer\Model\ResourceModel\Customer\Collection $collection */
        $collection = $this->customerFactory->create()->getCollection();
        $this->extensionAttributesJoinProcessor->process(
            $collection,
            CustomerInterface::class
        );
        // This is needed to make sure all the attributes are properly loaded
        foreach ($this->customerMetadata->getAllAttributesMetadata() as $metadata) {
            $collection->addAttributeToSelect($metadata->getAttributeCode());
        }
        // Needed to enable filtering on name as a whole
        $collection->addNameToSelect();
        // Needed to enable filtering based on billing address attributes
        $collection->joinAttribute('billing_postcode', 'customer_address/postcode', 'default_billing', null, 'left')
            ->joinAttribute('billing_city', 'customer_address/city', 'default_billing', null, 'left')
            ->joinAttribute('billing_telephone', 'customer_address/telephone', 'default_billing', null, 'left')
            ->joinAttribute('billing_region', 'customer_address/region', 'default_billing', null, 'left')
            ->joinAttribute('billing_country_id', 'customer_address/country_id', 'default_billing', null, 'left')
            ->joinAttribute('billing_company', 'customer_address/company', 'default_billing', null, 'left');

        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults->setTotalCount($collection->getSize());

        $customers = [];
        /** @var \Magento\Customer\Model\Customer $customerModel */
        foreach ($collection as $customerModel) {
            $customers[] = $customerModel->getDataModel();
        }
        $searchResults->setItems($customers);
        return $searchResults;
    }

    /**
     * Delete customer.
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface $customer
     * @return bool true on success
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function delete(CustomerInterface $customer)
    {
        return $this->deleteById($customer->getId());
    }

    /**
     * Delete customer by Customer ID.
     *
     * @param int $customerId
     * @return bool true on success
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function deleteById($customerId)
    {
        $customerModel = $this->customerRegistry->retrieve($customerId);
        $customerModel->delete();
        $this->customerRegistry->remove($customerId);
        $this->notificationStorage->remove(NotificationStorage::UPDATE_CUSTOMER_SESSION, $customerId);

        return true;
    }

    /**
     * Helper function that adds a FilterGroup to the collection.
     *
     * @deprecated 101.0.0
     * @param FilterGroup $filterGroup
     * @param Collection $collection
     * @return void
     */
    protected function addFilterGroupToCollection(FilterGroup $filterGroup, Collection $collection)
    {
        $fields = [];
        foreach ($filterGroup->getFilters() as $filter) {
            $condition = $filter->getConditionType() ? $filter->getConditionType() : 'eq';
            $fields[] = ['attribute' => $filter->getField(), $condition => $filter->getValue()];
        }
        if ($fields) {
            $collection->addFieldToFilter($fields);
        }
    }

    /**
     * Set ignore_validation_flag to skip model validation
     *
     * @param array $customerArray
     * @param Customer $customerModel
     * @return void
     */
    private function setValidationFlag($customerArray, $customerModel)
    {
        if (isset($customerArray['ignore_validation_flag'])) {
            $customerModel->setData('ignore_validation_flag', true);
        }
    }
}
