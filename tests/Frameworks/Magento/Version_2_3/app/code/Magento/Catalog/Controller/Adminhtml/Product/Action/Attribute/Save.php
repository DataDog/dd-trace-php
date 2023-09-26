<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Controller\Adminhtml\Product\Action\Attribute;

use Magento\AsynchronousOperations\Api\Data\OperationInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\Eav\Model\Config;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Backend\App\Action;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Class responsible for saving product attributes.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Save extends \Magento\Catalog\Controller\Adminhtml\Product\Action\Attribute implements HttpPostActionInterface
{
    /**
     * @var \Magento\Framework\Bulk\BulkManagementInterface
     */
    private $bulkManagement;

    /**
     * @var \Magento\AsynchronousOperations\Api\Data\OperationInterfaceFactory
     */
    private $operationFactory;

    /**
     * @var \Magento\Framework\DataObject\IdentityGeneratorInterface
     */
    private $identityService;

    /**
     * @var \Magento\Framework\Serialize\SerializerInterface
     */
    private $serializer;

    /**
     * @var \Magento\Authorization\Model\UserContextInterface
     */
    private $userContext;

    /**
     * @var int
     */
    private $bulkSize;

    /**
     * @var TimezoneInterface
     */
    private $timezone;

    /**
     * @var Config
     */
    private $eavConfig;

    /**
     * @var ProductFactory
     */
    private $productFactory;

    /**
     * @param Action\Context $context
     * @param \Magento\Catalog\Helper\Product\Edit\Action\Attribute $attributeHelper
     * @param \Magento\Framework\Bulk\BulkManagementInterface $bulkManagement
     * @param \Magento\AsynchronousOperations\Api\Data\OperationInterfaceFactory $operartionFactory
     * @param \Magento\Framework\DataObject\IdentityGeneratorInterface $identityService
     * @param \Magento\Framework\Serialize\SerializerInterface $serializer
     * @param \Magento\Authorization\Model\UserContextInterface $userContext
     * @param int $bulkSize
     * @param TimezoneInterface $timezone
     * @param Config $eavConfig
     * @param ProductFactory $productFactory
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Action\Context $context,
        \Magento\Catalog\Helper\Product\Edit\Action\Attribute $attributeHelper,
        \Magento\Framework\Bulk\BulkManagementInterface $bulkManagement,
        \Magento\AsynchronousOperations\Api\Data\OperationInterfaceFactory $operartionFactory,
        \Magento\Framework\DataObject\IdentityGeneratorInterface $identityService,
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        \Magento\Authorization\Model\UserContextInterface $userContext,
        int $bulkSize = 100,
        TimezoneInterface $timezone = null,
        Config $eavConfig = null,
        ProductFactory $productFactory = null
    ) {
        parent::__construct($context, $attributeHelper);
        $this->bulkManagement = $bulkManagement;
        $this->operationFactory = $operartionFactory;
        $this->identityService = $identityService;
        $this->serializer = $serializer;
        $this->userContext = $userContext;
        $this->bulkSize = $bulkSize;
        $this->timezone = $timezone ?: ObjectManager::getInstance()
            ->get(TimezoneInterface::class);
        $this->eavConfig = $eavConfig ?: ObjectManager::getInstance()
            ->get(Config::class);
        $this->productFactory = $productFactory ?? ObjectManager::getInstance()->get(ProductFactory::class);
    }

    /**
     * Update product attributes
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        if (!$this->_validateProducts()) {
            return $this->resultRedirectFactory->create()->setPath('catalog/product/', ['_current' => true]);
        }

        /* Collect Data */
        $attributesData = $this->getRequest()->getParam('attributes', []);
        $websiteRemoveData = $this->getRequest()->getParam('remove_website_ids', []);
        $websiteAddData = $this->getRequest()->getParam('add_website_ids', []);

        $storeId = $this->attributeHelper->getSelectedStoreId();
        $websiteId = $this->attributeHelper->getStoreWebsiteId($storeId);
        $productIds = $this->attributeHelper->getProductIds();

        $attributesData = $this->sanitizeProductAttributes($attributesData);

        try {
            $this->validateProductAttributes($attributesData);
            $this->publish($attributesData, $websiteRemoveData, $websiteAddData, $storeId, $websiteId, $productIds);
            $this->messageManager->addSuccessMessage(__('Message is added to queue'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage(
                $e,
                __('Something went wrong while updating the product(s) attributes.')
            );
        }

        return $this->resultRedirectFactory->create()->setPath('catalog/product/', ['store' => $storeId]);
    }

    /**
     * Sanitize product attributes
     *
     * @param array $attributesData
     *
     * @return array
     */
    private function sanitizeProductAttributes($attributesData)
    {
        $dateFormat = $this->timezone->getDateFormat(\IntlDateFormatter::SHORT);

        foreach ($attributesData as $attributeCode => $value) {
            $attribute = $this->eavConfig->getAttribute(\Magento\Catalog\Model\Product::ENTITY, $attributeCode);

            if (!$attribute->getAttributeId()) {
                unset($attributesData[$attributeCode]);
                continue;
            }

            if ($attribute->getBackendType() === 'datetime') {
                if (!empty($value)) {
                    $filterInput = new \Zend_Filter_LocalizedToNormalized(['date_format' => $dateFormat]);
                    $filterInternal = new \Zend_Filter_NormalizedToLocalized(
                        ['date_format' => \Magento\Framework\Stdlib\DateTime::DATE_INTERNAL_FORMAT]
                    );
                    $value = $filterInternal->filter($filterInput->filter($value));
                } else {
                    $value = null;
                }
                $attributesData[$attributeCode] = $value;
            } elseif ($attribute->getFrontendInput() === 'multiselect') {
                // Check if 'Change' checkbox has been checked by admin for this attribute
                $isChanged = (bool)$this->getRequest()->getPost('toggle_' . $attributeCode);
                if (!$isChanged) {
                    unset($attributesData[$attributeCode]);
                    continue;
                }
                if (is_array($value)) {
                    $value = implode(',', $value);
                }
                $attributesData[$attributeCode] = $value;
            }
        }
        return $attributesData;
    }

    /**
     * Validate product attributes data.
     *
     * @param array $attributesData
     *
     * @return void
     * @throws LocalizedException
     */
    private function validateProductAttributes(array $attributesData): void
    {
        $product = $this->productFactory->create();
        $product->setData($attributesData);

        foreach (array_keys($attributesData) as $attributeCode) {
            $attribute = $this->eavConfig->getAttribute(\Magento\Catalog\Model\Product::ENTITY, $attributeCode);
            $attribute->getBackend()->validate($product);
        }
    }

    /**
     * Schedule new bulk
     *
     * @param array $attributesData
     * @param array $websiteRemoveData
     * @param array $websiteAddData
     * @param int $storeId
     * @param int $websiteId
     * @param array $productIds
     * @throws LocalizedException
     *
     * @return void
     */
    private function publish(
        $attributesData,
        $websiteRemoveData,
        $websiteAddData,
        $storeId,
        $websiteId,
        $productIds
    ):void {
        $productIdsChunks = array_chunk($productIds, $this->bulkSize);
        $bulkUuid = $this->identityService->generateId();
        $bulkDescription = __('Update attributes for ' . count($productIds) . ' selected products');
        $operations = [];
        foreach ($productIdsChunks as $productIdsChunk) {
            if ($websiteRemoveData || $websiteAddData) {
                $dataToUpdate = [
                    'website_assign' => $websiteAddData,
                    'website_detach' => $websiteRemoveData
                ];
                $operations[] = $this->makeOperation(
                    'Update website assign',
                    'product_action_attribute.website.update',
                    $dataToUpdate,
                    $storeId,
                    $websiteId,
                    $productIdsChunk,
                    $bulkUuid
                );
            }

            if ($attributesData) {
                $operations[] = $this->makeOperation(
                    'Update product attributes',
                    'product_action_attribute.update',
                    $attributesData,
                    $storeId,
                    $websiteId,
                    $productIdsChunk,
                    $bulkUuid
                );
            }
        }

        if (!empty($operations)) {
            $result = $this->bulkManagement->scheduleBulk(
                $bulkUuid,
                $operations,
                $bulkDescription,
                $this->userContext->getUserId()
            );
            if (!$result) {
                throw new LocalizedException(
                    __('Something went wrong while processing the request.')
                );
            }
        }
    }

    /**
     * Make asynchronous operation
     *
     * @param string $meta
     * @param string $queue
     * @param array $dataToUpdate
     * @param int $storeId
     * @param int $websiteId
     * @param array $productIds
     * @param int $bulkUuid
     *
     * @return OperationInterface
     */
    private function makeOperation(
        $meta,
        $queue,
        $dataToUpdate,
        $storeId,
        $websiteId,
        $productIds,
        $bulkUuid
    ): OperationInterface {
        $dataToEncode = [
            'meta_information' => $meta,
            'product_ids' => $productIds,
            'store_id' => $storeId,
            'website_id' => $websiteId,
            'attributes' => $dataToUpdate
        ];
        $data = [
            'data' => [
                'bulk_uuid' => $bulkUuid,
                'topic_name' => $queue,
                'serialized_data' => $this->serializer->serialize($dataToEncode),
                'status' => \Magento\Framework\Bulk\OperationInterface::STATUS_TYPE_OPEN,
            ]
        ];

        return $this->operationFactory->create($data);
    }
}
