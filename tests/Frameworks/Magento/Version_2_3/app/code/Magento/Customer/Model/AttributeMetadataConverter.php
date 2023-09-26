<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Customer\Model;

use Magento\Customer\Api\Data\OptionInterfaceFactory;
use Magento\Customer\Api\Data\ValidationRuleInterfaceFactory;
use Magento\Customer\Api\Data\AttributeMetadataInterfaceFactory;
use Magento\Eav\Api\Data\AttributeDefaultValueInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;

/**
 * Converter for AttributeMetadata
 */
class AttributeMetadataConverter
{
    /**
     * Attribute Code get options from system config
     *
     * @var array
     */
    private const ATTRIBUTE_CODE_LIST_FROM_SYSTEM_CONFIG = ['prefix', 'suffix'];

    /**
     * XML Path to get address config
     *
     * @var string
     */
    private const XML_CUSTOMER_ADDRESS = 'customer/address/';

    /**
     * @var OptionInterfaceFactory
     */
    private $optionFactory;

    /**
     * @var ValidationRuleInterfaceFactory
     */
    private $validationRuleFactory;

    /**
     * @var AttributeMetadataInterfaceFactory
     */
    private $attributeMetadataFactory;

    /**
     * @var \Magento\Framework\Api\DataObjectHelper
     */
    protected $dataObjectHelper;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * Initialize the Converter
     *
     * @param OptionInterfaceFactory $optionFactory
     * @param ValidationRuleInterfaceFactory $validationRuleFactory
     * @param AttributeMetadataInterfaceFactory $attributeMetadataFactory
     * @param \Magento\Framework\Api\DataObjectHelper $dataObjectHelper
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        OptionInterfaceFactory $optionFactory,
        ValidationRuleInterfaceFactory $validationRuleFactory,
        AttributeMetadataInterfaceFactory $attributeMetadataFactory,
        \Magento\Framework\Api\DataObjectHelper $dataObjectHelper,
        ScopeConfigInterface $scopeConfig = null
    ) {
        $this->optionFactory = $optionFactory;
        $this->validationRuleFactory = $validationRuleFactory;
        $this->attributeMetadataFactory = $attributeMetadataFactory;
        $this->dataObjectHelper = $dataObjectHelper;
        $this->scopeConfig = $scopeConfig ?? ObjectManager::getInstance()->get(ScopeConfigInterface::class);
    }

    /**
     * Create AttributeMetadata Data object from the Attribute Model
     *
     * @param \Magento\Customer\Model\Attribute $attribute
     * @return \Magento\Customer\Api\Data\AttributeMetadataInterface
     */
    public function createMetadataAttribute($attribute)
    {
        $options = [];

        if (in_array($attribute->getAttributeCode(), self::ATTRIBUTE_CODE_LIST_FROM_SYSTEM_CONFIG)) {
            $options = $this->getOptionFromConfig($attribute->getAttributeCode());
        } else {
            if ($attribute->usesSource()) {
                foreach ($attribute->getSource()->getAllOptions() as $option) {
                    $optionDataObject = $this->optionFactory->create();
                    if (!is_array($option['value'])) {
                        $optionDataObject->setValue($option['value']);
                    } else {
                        $optionArray = [];
                        foreach ($option['value'] as $optionArrayValues) {
                            $optionObject = $this->optionFactory->create();
                            $this->dataObjectHelper->populateWithArray(
                                $optionObject,
                                $optionArrayValues,
                                \Magento\Customer\Api\Data\OptionInterface::class
                            );
                            $optionArray[] = $optionObject;
                        }
                        $optionDataObject->setOptions($optionArray);
                    }
                    $optionDataObject->setLabel($option['label']);
                    $options[] = $optionDataObject;
                }
            }
        }

        $validationRules = [];
        foreach ((array)$attribute->getValidateRules() as $name => $value) {
            $validationRule = $this->validationRuleFactory->create()
                ->setName($name)
                ->setValue($value);
            $validationRules[] = $validationRule;
        }

        $attributeMetaData = $this->attributeMetadataFactory->create();

        if ($attributeMetaData instanceof AttributeDefaultValueInterface) {
            $attributeMetaData->setDefaultValue($attribute->getDefaultValue());
        }

        return $attributeMetaData->setAttributeCode($attribute->getAttributeCode())
            ->setFrontendInput($attribute->getFrontendInput())
            ->setInputFilter((string)$attribute->getInputFilter())
            ->setStoreLabel($attribute->getStoreLabel())
            ->setValidationRules($validationRules)
            ->setIsVisible((boolean)$attribute->getIsVisible())
            ->setIsRequired((boolean)$attribute->getIsRequired())
            ->setMultilineCount((int)$attribute->getMultilineCount())
            ->setDataModel((string)$attribute->getDataModel())
            ->setOptions($options)
            ->setFrontendClass($attribute->getFrontend()->getClass())
            ->setFrontendLabel($attribute->getFrontendLabel())
            ->setNote((string)$attribute->getNote())
            ->setIsSystem((boolean)$attribute->getIsSystem())
            ->setIsUserDefined((boolean)$attribute->getIsUserDefined())
            ->setBackendType($attribute->getBackendType())
            ->setSortOrder((int)$attribute->getSortOrder())
            ->setIsUsedInGrid($attribute->getIsUsedInGrid())
            ->setIsVisibleInGrid($attribute->getIsVisibleInGrid())
            ->setIsFilterableInGrid($attribute->getIsFilterableInGrid())
            ->setIsSearchableInGrid($attribute->getIsSearchableInGrid());
    }

    /**
     * Get option from System Config instead of Use Source (Prefix, Suffix)
     *
     * @param string $attributeCode
     * @return \Magento\Customer\Api\Data\OptionInterface[]
     */
    private function getOptionFromConfig($attributeCode)
    {
        $result = [];
        $value = $this->scopeConfig->getValue(self::XML_CUSTOMER_ADDRESS . $attributeCode . '_options');
        if ($value) {
            $optionArray = explode(';', $value);
            foreach ($optionArray as $value) {
                $optionObject = $this->optionFactory->create();
                $optionObject->setLabel($value);
                $optionObject->setValue($value);
                $result[] = $optionObject;
            }
        }
        return $result;
    }
}
