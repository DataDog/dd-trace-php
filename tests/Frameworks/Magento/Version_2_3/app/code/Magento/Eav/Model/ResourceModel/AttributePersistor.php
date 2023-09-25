<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Eav\Model\ResourceModel;

use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Locale\FormatInterface;
use Magento\Framework\Model\Entity\ScopeInterface;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Class AttributePersistor
 */
class AttributePersistor
{
    /**
     * @var AttributeRepositoryInterface
     */
    private $attributeRepository;

    /**
     * @var FormatInterface
     */
    private $localeFormat;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * @var array
     */
    private $insert = [];

    /**
     * @var array
     */
    private $update = [];

    /**
     * @var array
     */
    private $delete = [];

    /**
     * @param FormatInterface $localeFormat
     * @param AttributeRepositoryInterface $attributeRepository
     * @param MetadataPool $metadataPool
     */
    public function __construct(
        FormatInterface $localeFormat,
        AttributeRepositoryInterface $attributeRepository,
        MetadataPool $metadataPool
    ) {
        $this->attributeRepository = $attributeRepository;
        $this->metadataPool = $metadataPool;
        $this->localeFormat = $localeFormat;
    }

    /**
     * @param string $entityType
     * @param int $link
     * @param string $attributeCode
     * @return void
     */
    public function registerDelete($entityType, $link, $attributeCode)
    {
        $this->delete[$entityType][$link][$attributeCode] = null;
    }

    /**
     * @param string $entityType
     * @param int $link
     * @param string $attributeCode
     * @param mixed $value
     * @return void
     */
    public function registerUpdate($entityType, $link, $attributeCode, $value)
    {
        $this->update[$entityType][$link][$attributeCode] = $value;
    }

    /**
     * @param string $entityType
     * @param int $link
     * @param string $attributeCode
     * @param mixed $value
     * @return void
     */
    public function registerInsert($entityType, $link, $attributeCode, $value)
    {
        $this->insert[$entityType][$link][$attributeCode] = $value;
    }

    /**
     * @param string $entityType
     * @param \Magento\Framework\Model\Entity\ScopeInterface[] $context
     * @return void
     * @throws \Exception
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function processDeletes($entityType, $context)
    {
        if (!isset($this->delete[$entityType]) || !is_array($this->delete[$entityType])) {
            return;
        }
        $metadata = $this->metadataPool->getMetadata($entityType);
        foreach ($this->delete[$entityType] as $link => $data) {
            $attributeCodes = array_keys($data);
            foreach ($attributeCodes as $attributeCode) {
                /** @var AbstractAttribute $attribute */
                $attribute = $this->attributeRepository->get($metadata->getEavEntityType(), $attributeCode);
                $conditions = $this->buildDeleteConditions($attribute, $metadata, $context, $link);

                foreach ($conditions as $condition) {
                    $metadata->getEntityConnection()->delete(
                        $attribute->getBackend()->getTable(),
                        $condition
                    );
                }
            }
        }
    }

    /**
     * @param string $entityType
     * @param \Magento\Framework\Model\Entity\ScopeInterface[] $context
     * @return void
     * @throws \Exception
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function processInserts($entityType, $context)
    {
        if (!isset($this->insert[$entityType]) || !is_array($this->insert[$entityType])) {
            return;
        }
        $metadata = $this->metadataPool->getMetadata($entityType);
        $insertData = $this->prepareInsertDataForMultipleSave($entityType, $context);

        foreach ($insertData as $table => $tableData) {
            foreach ($tableData as $data) {
                $metadata->getEntityConnection()->insertArray(
                    $table,
                    $data['columns'],
                    $data['data'],
                    \Magento\Framework\DB\Adapter\AdapterInterface::INSERT_IGNORE
                );
            }
        }
    }

    /**
     * Prepare data for insert multiple rows
     *
     * @param string $entityType
     * @param \Magento\Framework\Model\Entity\ScopeInterface[] $context
     * @return array
     */
    private function prepareInsertDataForMultipleSave($entityType, $context)
    {
        $metadata = $this->metadataPool->getMetadata($entityType);
        $insertData = [];
        foreach ($this->insert[$entityType] as $link => $data) {
            foreach ($data as $attributeCode => $attributeValue) {
                /** @var AbstractAttribute $attribute */
                $attribute = $this->attributeRepository->get(
                    $metadata->getEavEntityType(),
                    $attributeCode
                );
                $attributeTable = $attribute->getBackend()->getTable();
                $conditions = $this->buildInsertConditions($attribute, $metadata, $context, $link);
                $value = $this->prepareValue($entityType, $attributeValue, $attribute);

                foreach ($conditions as $condition) {
                    $condition['value'] = $value;
                    $columns = array_keys($condition);
                    $columnsHash = implode('', $columns);
                    $insertData[$attributeTable][$columnsHash]['columns'] = $columns;
                    $insertData[$attributeTable][$columnsHash]['data'][] = array_values($condition);
                }
            }
        }

        return $insertData;
    }

    /**
     * @param string $entityType
     * @param \Magento\Framework\Model\Entity\ScopeInterface[] $context
     * @return void
     * @throws \Exception
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function processUpdates($entityType, $context)
    {
        if (!isset($this->update[$entityType]) || !is_array($this->update[$entityType])) {
            return;
        }
        $metadata = $this->metadataPool->getMetadata($entityType);
        foreach ($this->update[$entityType] as $link => $data) {
            foreach ($data as $attributeCode => $attributeValue) {
                /** @var AbstractAttribute $attribute */
                $attribute = $this->attributeRepository->get(
                    $metadata->getEavEntityType(),
                    $attributeCode
                );
                $conditions = $this->buildUpdateConditions($attribute, $metadata, $context, $link);

                foreach ($conditions as $condition) {
                    $metadata->getEntityConnection()->update(
                        $attribute->getBackend()->getTable(),
                        [
                            'value' => $this->prepareValue($entityType, $attributeValue, $attribute)
                        ],
                        $condition
                    );
                }
            }
        }
    }

    /**
     * Builds set of update conditions (WHERE clause)
     *
     * @param AbstractAttribute $attribute
     * @param EntityMetadataInterface $metadata
     * @param ScopeInterface[] $scopes
     * @param string $linkFieldValue
     * @return array
     */
    protected function buildUpdateConditions(
        AbstractAttribute $attribute,
        EntityMetadataInterface $metadata,
        array $scopes,
        $linkFieldValue
    ) {
        $condition = [
            $metadata->getLinkField() . ' = ?' => $linkFieldValue,
            'attribute_id = ?' => $attribute->getAttributeId(),
        ];

        foreach ($scopes as $scope) {
            $identifier = $metadata->getEntityConnection()->quoteIdentifier($scope->getIdentifier());
            $condition[$identifier . ' = ?'] = $this->getScopeValue($scope, $attribute);
        }

        return [
            $condition,
        ];
    }

    /**
     * Builds set of delete conditions (WHERE clause)
     *
     * @param AbstractAttribute $attribute
     * @param EntityMetadataInterface $metadata
     * @param ScopeInterface[] $scopes
     * @param string $linkFieldValue
     * @return array
     */
    protected function buildDeleteConditions(
        AbstractAttribute $attribute,
        EntityMetadataInterface $metadata,
        array $scopes,
        $linkFieldValue
    ) {
        $condition = [
            $metadata->getLinkField() . ' = ?' => $linkFieldValue,
            'attribute_id = ?' => $attribute->getAttributeId(),
        ];

        foreach ($scopes as $scope) {
            $identifier = $metadata->getEntityConnection()->quoteIdentifier($scope->getIdentifier());
            $condition[$identifier . ' = ?'] = $this->getScopeValue($scope, $attribute);
        }

        return [
            $condition,
        ];
    }

    /**
     * Builds set of insert conditions
     *
     * @param AbstractAttribute $attribute
     * @param EntityMetadataInterface $metadata
     * @param ScopeInterface[] $scopes
     * @param string $linkFieldValue
     * @return array
     */
    protected function buildInsertConditions(
        AbstractAttribute $attribute,
        EntityMetadataInterface $metadata,
        array $scopes,
        $linkFieldValue
    ) {
        $condition = [
            $metadata->getLinkField() => $linkFieldValue,
            'attribute_id' => $attribute->getAttributeId(),
        ];

        foreach ($scopes as $scope) {
            $condition[$scope->getIdentifier()] = $this->getScopeValue($scope, $attribute);
        }

        return [
            $condition,
        ];
    }

    /**
     * Flush attributes to storage
     *
     * @param string $entityType
     * @param ScopeInterface[] $context
     * @return void
     */
    public function flush($entityType, $context)
    {
        $this->processDeletes($entityType, $context);
        $this->processInserts($entityType, $context);
        $this->processUpdates($entityType, $context);
        unset($this->delete, $this->insert, $this->update);
    }

    /**
     * @param string $entityType
     * @param string $value
     * @param AbstractAttribute $attribute
     * @return mixed
     * @throws \Exception
     */
    protected function prepareValue($entityType, $value, AbstractAttribute $attribute)
    {
        $metadata = $this->metadataPool->getMetadata($entityType);
        $type = $attribute->getBackendType();
        if (($type == 'int' || $type == 'decimal' || $type == 'datetime') && $value === '') {
            $value = null;
        } elseif ($type == 'decimal') {
            $value = $this->localeFormat->getNumber($value);
        } elseif ($type == 'varchar' && is_array($value)) {
            $value = implode(',', $value);
        }
        $describe = $metadata->getEntityConnection()->describeTable($attribute->getBackendTable());
        return $metadata->getEntityConnection()->prepareColumnValue($describe['value'], $value);
    }

    /**
     * @param ScopeInterface $scope
     * @param AbstractAttribute $attribute
     * @param bool $useDefault
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function getScopeValue(ScopeInterface $scope, AbstractAttribute $attribute, $useDefault = false)
    {
        if ($useDefault && $scope->getFallback()) {
            return $this->getScopeValue($scope->getFallback(), $attribute, $useDefault);
        }
        return $scope->getValue();
    }
}
