<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Theme\Model\Indexer\Design\Config;

use Magento\Framework\Indexer\FieldsetInterface;
use Magento\Theme\Model\Design\Config\MetadataProviderInterface;

class FieldsProvider implements FieldsetInterface
{
    /**
     * @var MetadataProviderInterface
     */
    protected $metadataProvider;

    /**
     * @param MetadataProviderInterface $metadataProvider
     */
    public function __construct(
        MetadataProviderInterface $metadataProvider
    ) {
        $this->metadataProvider = $metadataProvider;
    }

    /**
     * Add additional fields to fieldset
     *
     * @param array $data
     * @return array
     */
    public function addDynamicData(array $data)
    {
        $additionalFields = $this->convert($this->metadataProvider->get());
        $data['fields'] = $this->merge($data['fields'], $additionalFields);

        return $data;
    }

    /**
     * Convert metadata to fields
     *
     * @param array $metadata
     * @return array
     */
    protected function convert(array $metadata)
    {
        $fields = [];
        foreach ($metadata as $itemName => $itemData) {
            if (isset($itemData['use_in_grid']) && (boolean)$itemData['use_in_grid']) {
                $fields[$itemName] = [
                    'name' => $itemName,
                    'origin' => 'value',
                    'handler' => \Magento\Framework\Indexer\Handler\DefaultHandler::class,
                    'type' => 'searchable',
                ];
            }
        }

        return $fields;
    }

    /**
     * Merge fields with metadata fields
     *
     * @param array $dataFields
     * @param array $searchableFields
     * @return array
     */
    protected function merge(array $dataFields, array $searchableFields)
    {
        foreach ($searchableFields as $name => $field) {
            if (!isset($field['name']) && !isset($dataFields[$name])) {
                continue;
            }
            if (!isset($dataFields[$name])) {
                $dataFields[$name] = [];
            }
            foreach ($field as $key => $value) {
                $dataFields[$name][$key] = $value;
            }
        }

        return $dataFields;
    }
}
