<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Controller\Adminhtml\File\Address;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Customer\Api\AddressMetadataInterface;
use Magento\Customer\Model\FileUploader;
use Magento\Customer\Model\FileUploaderFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Uploads files for customer address
 */
class Upload extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Magento_Customer::manage';

    /**
     * @var FileUploaderFactory
     */
    private $fileUploaderFactory;

    /**
     * @var AddressMetadataInterface
     */
    private $addressMetadataService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var string
     */
    private $scope;

    /**
     * @param Context $context
     * @param FileUploaderFactory $fileUploaderFactory
     * @param AddressMetadataInterface $addressMetadataService
     * @param LoggerInterface $logger
     * @param string $scope
     */
    public function __construct(
        Context $context,
        FileUploaderFactory $fileUploaderFactory,
        AddressMetadataInterface $addressMetadataService,
        LoggerInterface $logger,
        string $scope = 'address'
    ) {
        $this->fileUploaderFactory = $fileUploaderFactory;
        $this->addressMetadataService = $addressMetadataService;
        $this->logger = $logger;
        $this->scope = $scope;
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        try {
            if (empty($_FILES)) {
                throw new \Exception('$_FILES array is empty.');
            }

            // Must be executed before any operations with $_FILES!
            $this->convertFilesArray();

            $attributeCode = key($_FILES[$this->scope]['name']);
            $attributeMetadata = $this->addressMetadataService->getAttributeMetadata($attributeCode);

            /** @var FileUploader $fileUploader */
            $fileUploader = $this->fileUploaderFactory->create([
                'attributeMetadata' => $attributeMetadata,
                'entityTypeCode' => AddressMetadataInterface::ENTITY_TYPE_ADDRESS,
                'scope' => $this->scope,
            ]);

            $errors = $fileUploader->validate();
            if (true !== $errors) {
                $errorMessage = implode('</br>', $errors);
                throw new LocalizedException(__($errorMessage));
            }

            $result = $fileUploader->upload();
        } catch (LocalizedException $e) {
            $result = [
                'error' => $e->getMessage(),
                'errorcode' => $e->getCode(),
            ];
        } catch (\Exception $e) {
            $this->logger->critical($e);
            $result = [
                'error' => __('Something went wrong while saving file.'),
                'errorcode' => $e->getCode(),
            ];
        }

        /** @var \Magento\Framework\Controller\Result\Json $resultJson */
        $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $resultJson->setData($result);
        return $resultJson;
    }

    /**
     * Update global $_FILES array. Convert data to standard form
     *
     * NOTE: This conversion is required to use \Magento\Framework\File\Uploader::_setUploadFileId($fileId) method.
     *
     * @return void
     */
    private function convertFilesArray()
    {
        foreach ($_FILES as $itemKey => $item) {
            foreach ($item as $fieldName => $value) {
                    $_FILES[$this->scope][$fieldName] = [$itemKey => $value];
            }
            unset($_FILES[$itemKey]);
        }
    }
}
