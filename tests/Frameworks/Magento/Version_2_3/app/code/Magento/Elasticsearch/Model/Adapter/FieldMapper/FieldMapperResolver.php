<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Elasticsearch\Model\Adapter\FieldMapper;

use Magento\Framework\ObjectManagerInterface;
use Magento\Elasticsearch\Model\Adapter\FieldMapperInterface;
use Magento\Elasticsearch\Model\Config;

/**
 * Field Mapper resolver.
 */
class FieldMapperResolver implements FieldMapperInterface
{
    /**
     * Object Manager instance
     *
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var string[]
     */
    private $fieldMappers;

    /**
     * Field Mapper instance per entity
     *
     * @var FieldMapperInterface[]
     */
    private $fieldMapperEntity = [];

    /**
     * @param ObjectManagerInterface $objectManager
     * @param string[] $fieldMappers
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        array $fieldMappers = []
    ) {
        $this->objectManager = $objectManager;
        $this->fieldMappers = $fieldMappers;
    }

    /**
     * @inheritdoc
     */
    public function getFieldName($attributeCode, $context = [])
    {
        $entityType = isset($context['entityType']) ? $context['entityType'] : Config::ELASTICSEARCH_TYPE_DEFAULT;
        return $this->getEntity($entityType)->getFieldName($attributeCode, $context);
    }

    /**
     * @inheritdoc
     */
    public function getAllAttributesTypes($context = [])
    {
        $entityType = isset($context['entityType']) ? $context['entityType'] : Config::ELASTICSEARCH_TYPE_DEFAULT;
        return $this->getEntity($entityType)->getAllAttributesTypes($context);
    }

    /**
     * Get instance of current field mapper
     *
     * @param string $entityType
     * @return FieldMapperInterface
     * @throws \Exception
     */
    private function getEntity($entityType)
    {
        if (empty($this->fieldMapperEntity[$entityType])) {
            if (empty($entityType)) {
                // phpcs:ignore Magento2.Exceptions.DirectThrow
                throw new \Exception(
                    'No entity type given'
                );
            }
            if (!isset($this->fieldMappers[$entityType])) {
                throw new \LogicException(
                    'There is no such field mapper: ' . $entityType
                );
            }
            $fieldMapperClass = $this->fieldMappers[$entityType];
            $this->fieldMapperEntity[$entityType] = $this->objectManager->create($fieldMapperClass);
            if (!($this->fieldMapperEntity[$entityType] instanceof FieldMapperInterface)) {
                throw new \InvalidArgumentException(
                    'Field mapper must implement \Magento\Elasticsearch\Model\Adapter\FieldMapperInterface'
                );
            }
        }
        return $this->fieldMapperEntity[$entityType];
    }
}
