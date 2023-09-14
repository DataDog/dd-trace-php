<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Controller\Adminhtml\Product\Attribute;

use Magento\Backend\App\Action\Context;
use Magento\Catalog\Controller\Adminhtml\Product\Attribute as AttributeAction;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Entity\Attribute\Set;
use Magento\Eav\Model\Validator\Attribute\Code as AttributeCodeValidator;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface as HttpPostActionInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Escaper;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\FormData;
use Magento\Framework\View\LayoutFactory;
use Magento\Framework\View\Result\PageFactory;

/**
 * Product attribute validate controller.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Validate extends AttributeAction implements HttpGetActionInterface, HttpPostActionInterface
{
    const DEFAULT_MESSAGE_KEY = 'message';
    private const RESERVED_ATTRIBUTE_CODES = ['product_type', 'type_id'];

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var LayoutFactory
     */
    protected $layoutFactory;

    /**
     * @var array
     */
    private $multipleAttributeList;

    /**
     * @var FormData|null
     */
    private $formDataSerializer;

    /**
     * @var AttributeCodeValidator
     */
    private $attributeCodeValidator;

    /**
     * @var Escaper
     */
    private $escaper;

    /**
     * Constructor
     *
     * @param Context $context
     * @param FrontendInterface $attributeLabelCache
     * @param Registry $coreRegistry
     * @param PageFactory $resultPageFactory
     * @param JsonFactory $resultJsonFactory
     * @param LayoutFactory $layoutFactory
     * @param array $multipleAttributeList
     * @param FormData|null $formDataSerializer
     * @param AttributeCodeValidator|null $attributeCodeValidator
     * @param Escaper $escaper
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        FrontendInterface $attributeLabelCache,
        Registry $coreRegistry,
        PageFactory $resultPageFactory,
        JsonFactory $resultJsonFactory,
        LayoutFactory $layoutFactory,
        array $multipleAttributeList = [],
        FormData $formDataSerializer = null,
        AttributeCodeValidator $attributeCodeValidator = null,
        Escaper $escaper = null
    ) {
        parent::__construct($context, $attributeLabelCache, $coreRegistry, $resultPageFactory);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->layoutFactory = $layoutFactory;
        $this->multipleAttributeList = $multipleAttributeList;
        $this->formDataSerializer = $formDataSerializer ?: ObjectManager::getInstance()
            ->get(FormData::class);
        $this->attributeCodeValidator = $attributeCodeValidator ?: ObjectManager::getInstance()
            ->get(AttributeCodeValidator::class);
        $this->escaper = $escaper ?: ObjectManager::getInstance()
            ->get(Escaper::class);
    }

    /**
     * @inheritdoc
     *
     * @return ResultInterface
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function execute()
    {
        $response = new DataObject();
        $response->setError(false);
        try {
            $optionsData = $this->formDataSerializer
                ->unserialize($this->getRequest()->getParam('serialized_options', '[]'));
        } catch (\InvalidArgumentException $e) {
            $message = __(
                "The attribute couldn't be validated due to an error. Verify your information and try again. "
                . "If the error persists, please try again later."
            );
            $this->setMessageToResponse($response, [$message]);
            $response->setError(true);
        }

        $attributeCode = $this->getRequest()->getParam('attribute_code');
        $frontendLabel = $this->getRequest()->getParam('frontend_label');
        $attributeId = $this->getRequest()->getParam('attribute_id');

        if ($attributeId) {
            $attribute = $this->_objectManager->create(
                Attribute::class
            )->load($attributeId);
            $attributeCode = $attribute->getAttributeCode();
        } else {
            $attributeCode = $attributeCode ?: $this->generateCode($frontendLabel[0]);
            $attribute = $this->_objectManager->create(
                Attribute::class
            )->loadByCode(
                $this->_entityTypeId,
                $attributeCode
            );
        }

        if (in_array($attributeCode, self::RESERVED_ATTRIBUTE_CODES, true)) {
            $message = __('Code (%1) is a reserved key and cannot be used as attribute code.', $attributeCode);
            $this->setMessageToResponse($response, [$message]);
            $response->setError(true);
        }

        if ($attribute->getId() && !$attributeId) {
            $message = strlen($this->getRequest()->getParam('attribute_code'))
                ? __('An attribute with this code already exists.')
                : __('An attribute with the same code (%1) already exists.', $attributeCode);
            $this->setMessageToResponse($response, [$message]);

            $response->setError(true);
            $response->setProductAttribute($attribute->toArray());
        }

        if (!$this->attributeCodeValidator->isValid($attributeCode)) {
            $this->setMessageToResponse($response, $this->attributeCodeValidator->getMessages());
            $response->setError(true);
        }

        if ($this->getRequest()->has('new_attribute_set_name')) {
            $setName = $this->getRequest()->getParam('new_attribute_set_name');
            /** @var $attributeSet Set */
            $attributeSet = $this->_objectManager->create(Set::class);
            $attributeSet->setEntityTypeId($this->_entityTypeId)->load($setName, 'attribute_set_name');
            if ($attributeSet->getId()) {
                $setName = $this->escaper->escapeHtml($setName);
                $this->messageManager->addErrorMessage(__('An attribute set named \'%1\' already exists.', $setName));

                $layout = $this->layoutFactory->create();
                $layout->initMessages();
                $response->setError(true);
                $response->setHtmlMessage($layout->getMessagesBlock()->getGroupedHtml());
            }
        }

        $multipleOption = $this->getRequest()->getParam("frontend_input");
        $multipleOption = (null === $multipleOption) ? 'select' : $multipleOption;

        if (isset($this->multipleAttributeList[$multipleOption])) {
            $options = $optionsData[$this->multipleAttributeList[$multipleOption]] ?? null;
            $this->checkUniqueOption(
                $response,
                $options
            );
            $valueOptions = (isset($options['value']) && is_array($options['value'])) ? $options['value'] : [];
            foreach (array_keys($valueOptions) as $key) {
                if (!empty($options['delete'][$key])) {
                    unset($valueOptions[$key]);
                }
            }
            $this->checkEmptyOption($response, $valueOptions);
        }

        return $this->resultJsonFactory->create()->setJsonData($response->toJson());
    }

    /**
     * Throws Exception if not unique values into options.
     *
     * @param array $optionsValues
     * @param array $deletedOptions
     * @return bool
     */
    private function isUniqueAdminValues(array $optionsValues, array $deletedOptions)
    {
        $adminValues = [];
        foreach ($optionsValues as $optionKey => $values) {
            if (!(isset($deletedOptions[$optionKey]) && $deletedOptions[$optionKey] === '1')) {
                $adminValues[] = reset($values);
            }
        }
        $uniqueValues = array_unique($adminValues);
        return array_diff_assoc($adminValues, $uniqueValues);
    }

    /**
     * Set message to response object
     *
     * @param DataObject $response
     * @param string[] $messages
     * @return DataObject
     */
    private function setMessageToResponse($response, $messages)
    {
        $messageKey = $this->getRequest()->getParam('message_key', static::DEFAULT_MESSAGE_KEY);
        if ($messageKey === static::DEFAULT_MESSAGE_KEY) {
            $messages = reset($messages);
        }
        return $response->setData($messageKey, $messages);
    }

    /**
     * Performs checking the uniqueness of the attribute options.
     *
     * @param DataObject $response
     * @param array|null $options
     * @return $this
     */
    private function checkUniqueOption(DataObject $response, array $options = null)
    {
        if (is_array($options)
            && isset($options['value'])
            && isset($options['delete'])
            && !empty($options['value'])
            && !empty($options['delete'])
        ) {
            $duplicates = $this->isUniqueAdminValues($options['value'], $options['delete']);
            if (!empty($duplicates)) {
                $this->setMessageToResponse(
                    $response,
                    [__('The value of Admin must be unique. (%1)', implode(', ', $duplicates))]
                );
                $response->setError(true);
            }
        }
        return $this;
    }

    /**
     * Check that admin does not try to create option with empty admin scope option.
     *
     * @param DataObject $response
     * @param array $optionsForCheck
     * @return void
     */
    private function checkEmptyOption(DataObject $response, array $optionsForCheck = null)
    {
        foreach ($optionsForCheck as $optionValues) {
            if (isset($optionValues[0]) && trim((string)$optionValues[0]) == '') {
                $this->setMessageToResponse($response, [__("The value of Admin scope can't be empty.")]);
                $response->setError(true);
            }
        }
    }
}
