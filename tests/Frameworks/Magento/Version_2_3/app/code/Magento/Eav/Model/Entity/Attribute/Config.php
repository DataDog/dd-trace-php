<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Eav\Model\Entity\Attribute;

use Magento\Framework\Serialize\SerializerInterface;

/**
 * Provides EAV attributes configuration
 *
 * @api
 * @since 100.0.2
 */
class Config extends \Magento\Framework\Config\Data
{
    /**
     * Constructor
     *
     * @param Config\Reader $reader
     * @param \Magento\Framework\Config\CacheInterface $cache
     * @param string|null $cacheId
     * @param SerializerInterface|null $serializer
     */
    public function __construct(
        \Magento\Eav\Model\Entity\Attribute\Config\Reader $reader,
        \Magento\Framework\Config\CacheInterface $cache,
        $cacheId = 'eav_attributes',
        SerializerInterface $serializer = null
    ) {
        parent::__construct($reader, $cache, $cacheId, $serializer);
    }

    /**
     * Retrieve list of locked fields for attribute
     *
     * @param AbstractAttribute $attribute
     * @return array
     */
    public function getLockedFields(AbstractAttribute $attribute)
    {
        $allFields = $this->get(
            $attribute->getEntityType()->getEntityTypeCode() . '/attributes/' . $attribute->getAttributeCode()
        );

        if (!is_array($allFields)) {
            return [];
        }
        $lockedFields = [];
        foreach (array_keys($allFields) as $fieldCode) {
            $lockedFields[$fieldCode] = $fieldCode;
        }

        return $lockedFields;
    }

    /**
     * Retrieve attributes list with config for entity
     *
     * @param string $entityCode
     * @return array
     */
    public function getEntityAttributesLockedFields($entityCode)
    {
        $lockedFields = [];

        $entityAttributes = $this->get($entityCode . '/attributes');
        foreach ($entityAttributes as $attributeCode => $attributeData) {
            foreach ($attributeData as $attributeField) {
                if ($attributeField['locked']) {
                    $lockedFields[$attributeCode][] = $attributeField['code'];
                }
            }
        }

        return $lockedFields;
    }
}
