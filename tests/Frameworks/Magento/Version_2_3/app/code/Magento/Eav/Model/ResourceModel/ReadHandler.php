<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Eav\Model\ResourceModel;

use Exception;
use Magento\Eav\Model\Config;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\UnionExpression;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\EntityManager\Operation\AttributeInterface;
use Magento\Framework\Exception\ConfigurationMismatchException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Entity\ScopeInterface;
use Magento\Framework\Model\Entity\ScopeResolver;
use Psr\Log\LoggerInterface;

/**
 * EAV read handler
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ReadHandler implements AttributeInterface
{
    /**
     * @var MetadataPool
     */
    protected $metadataPool;

    /**
     * @var ScopeResolver
     */
    protected $scopeResolver;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Config
     */
    private $config;

    /**
     * @param MetadataPool $metadataPool
     * @param ScopeResolver $scopeResolver
     * @param LoggerInterface $logger
     * @param Config $config
     */
    public function __construct(
        MetadataPool $metadataPool,
        ScopeResolver $scopeResolver,
        LoggerInterface $logger,
        Config $config
    ) {
        $this->metadataPool = $metadataPool;
        $this->scopeResolver = $scopeResolver;
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Get attribute of given entity type
     *
     * @param string $entityType
     * @return \Magento\Eav\Api\Data\AttributeInterface[]
     * @throws Exception if for unknown entity type
     * @deprecated 101.0.5 Not used anymore
     * @see ReadHandler::getEntityAttributes
     */
    protected function getAttributes($entityType)
    {
        $metadata = $this->metadataPool->getMetadata($entityType);
        $eavEntityType = $metadata->getEavEntityType();
        return null === $eavEntityType ? [] : $this->config->getEntityAttributes($eavEntityType);
    }

    /**
     * Get attribute of given entity type
     *
     * @param string $entityType
     * @param DataObject $entity
     * @return \Magento\Eav\Api\Data\AttributeInterface[]
     * @throws Exception if for unknown entity type
     */
    private function getEntityAttributes(string $entityType, DataObject $entity): array
    {
        $metadata = $this->metadataPool->getMetadata($entityType);
        $eavEntityType = $metadata->getEavEntityType();
        return null === $eavEntityType ? [] : $this->config->getEntityAttributes($eavEntityType, $entity);
    }

    /**
     * Get context variables
     *
     * @param ScopeInterface $scope
     * @return array
     */
    protected function getContextVariables(ScopeInterface $scope)
    {
        $data[] = $scope->getValue();
        if ($scope->getFallback()) {
            $data = array_merge($data, $this->getContextVariables($scope->getFallback()));
        }
        return $data;
    }

    /**
     * Execute read handler
     *
     * @param string $entityType
     * @param array $entityData
     * @param array $arguments
     * @return array
     * @throws Exception
     * @throws ConfigurationMismatchException
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function execute($entityType, $entityData, $arguments = [])
    {
        $metadata = $this->metadataPool->getMetadata($entityType);
        if (!$metadata->getEavEntityType()) {//todo hasCustomAttributes
            return $entityData;
        }
        $context = $this->scopeResolver->getEntityContext($entityType, $entityData);
        $connection = $metadata->getEntityConnection();

        $attributeTables = [];
        $attributesMap = [];
        $selects = [];

        /** @var AbstractAttribute $attribute */
        foreach ($this->getEntityAttributes($entityType, new DataObject($entityData)) as $attribute) {
            if (!$attribute->isStatic()) {
                $attributeTables[$attribute->getBackend()->getTable()][] = $attribute->getAttributeId();
                $attributesMap[$attribute->getAttributeId()] = $attribute->getAttributeCode();
            }
        }
        if (count($attributeTables)) {
            $identifiers = null;
            foreach ($attributeTables as $attributeTable => $attributeIds) {
                $select = $connection->select()
                    ->from(
                        ['t' => $attributeTable],
                        ['value' => 't.value', 'attribute_id' => 't.attribute_id']
                    )
                    ->where($metadata->getLinkField() . ' = ?', $entityData[$metadata->getLinkField()])
                    ->where('attribute_id IN (?)', $attributeIds);
                $attributeIdentifiers = [];
                foreach ($context as $scope) {
                    //TODO: if (in table exists context field)
                    $select->where(
                        $connection->quoteIdentifier($scope->getIdentifier()) . ' IN (?)',
                        $this->getContextVariables($scope)
                    );
                    $attributeIdentifiers[] = $scope->getIdentifier();
                }
                $attributeIdentifiers = array_unique($attributeIdentifiers);
                $identifiers = array_intersect($identifiers ?? $attributeIdentifiers, $attributeIdentifiers);
                $selects[] = $select;
            }
            $this->applyIdentifierForSelects($selects, $identifiers);
            $unionSelect = new UnionExpression($selects, Select::SQL_UNION_ALL, '( %s )');
            $orderedUnionSelect = $connection->select();
            $orderedUnionSelect->from(['u' => $unionSelect]);
            $this->applyIdentifierForUnion($orderedUnionSelect, $identifiers);
            $attributes = $connection->fetchAll($orderedUnionSelect);
            foreach ($attributes as $attributeValue) {
                if (isset($attributesMap[$attributeValue['attribute_id']])) {
                    $entityData[$attributesMap[$attributeValue['attribute_id']]] = $attributeValue['value'];
                } else {
                    $this->logger->warning(
                        "Attempt to load value of nonexistent EAV attribute",
                        [
                            'attribute_id' => $attributeValue['attribute_id'],
                            'entity_type' => $entityType
                        ]
                    );
                }
            }
        }
        return $entityData;
    }

    /**
     * Apply identifiers column on select array
     *
     * @param Select[] $selects
     * @param array $identifiers
     * @return void
     */
    private function applyIdentifierForSelects(array $selects, array $identifiers): void
    {
        foreach ($selects as $select) {
            foreach ($identifiers as $identifier) {
                $select->columns($identifier, 't');
            }
        }
    }

    /**
     * Apply identifiers order on union select
     *
     * @param Select $unionSelect
     * @param array $identifiers
     */
    private function applyIdentifierForUnion(Select $unionSelect, array $identifiers)
    {
        foreach ($identifiers as $identifier) {
            $unionSelect->order($identifier);
        }
    }
}
