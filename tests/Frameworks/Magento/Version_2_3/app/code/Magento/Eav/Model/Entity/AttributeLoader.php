<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Eav\Model\Entity;

use Magento\Eav\Model\Config;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;

/**
 * Attribute loader
 */
class AttributeLoader implements AttributeLoaderInterface
{
    /**
     * Default attributes
     *
     * @var array
     */
    private $defaultAttributes = [];

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var Config
     */
    private $config;

    /**
     * Constructor
     *
     * @param Config $config
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(
        Config $config,
        ObjectManagerInterface $objectManager
    ) {
        $this->config = $config;
        $this->objectManager = $objectManager;
    }

    /**
     * Retrieve configuration for all attributes
     *
     * @param AbstractEntity $resource
     * @param DataObject|null $object
     * @return AbstractEntity
     * @throws LocalizedException
     */
    public function loadAllAttributes(AbstractEntity $resource, DataObject $object = null)
    {
        $attributes = $this->config->getEntityAttributes($resource->getEntityType(), $object);
        $attributeCodes = array_keys($attributes);
        /**
         * Check and init default attributes
         */
        $defaultAttributesCodes = array_diff($resource->getDefaultAttributes(), $attributeCodes);

        $resource->unsetAttributes();

        foreach ($defaultAttributesCodes as $attributeCode) {
            $resource->addAttribute($this->_getDefaultAttribute($resource, $attributeCode), $object);
        }
        foreach ($attributes as $attributeCode => $attribute) {
            $resource->addAttribute($attribute, $object);
        }
        return $resource;
    }

    /**
     * Return default static virtual attribute that doesn't exists in EAV attributes
     *
     * @param AbstractEntity $resource
     * @param string $attributeCode
     * @return Attribute
     */
    protected function _getDefaultAttribute(AbstractEntity $resource, $attributeCode)
    {
        $entityTypeId = $resource->getEntityType()->getId();
        if (!isset($this->defaultAttributes[$entityTypeId][$attributeCode])) {
            $attribute = $this->objectManager->create($resource->getEntityType()->getAttributeModel())
                ->setAttributeCode($attributeCode)
                ->setBackendType(AbstractAttribute::TYPE_STATIC)
                ->setIsGlobal(1)
                ->setEntityType($resource->getEntityType())
                ->setEntityTypeId($resource->getEntityType()->getId());
            $this->defaultAttributes[$entityTypeId][$attributeCode] = $attribute;
        }
        return $this->defaultAttributes[$entityTypeId][$attributeCode];
    }
}
