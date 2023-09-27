<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Ui\Component;

use Magento\Customer\Api\Data\AttributeMetadataInterface as AttributeMetadata;

/**
 * Class FilterFactory. Responsible for generation filter object
 */
class FilterFactory
{
    /**
     * @var \Magento\Framework\View\Element\UiComponentFactory
     */
    protected $componentFactory;

    /**
     * @var array
     */
    protected $filterMap = [
        'default' => 'filterInput',
        'select' => 'filterSelect',
        'boolean' => 'filterSelect',
        'multiselect' => 'filterSelect',
        'date' => 'filterDate',
    ];

    /**
     * @param \Magento\Framework\View\Element\UiComponentFactory $componentFactory
     */
    public function __construct(\Magento\Framework\View\Element\UiComponentFactory $componentFactory)
    {
        $this->componentFactory = $componentFactory;
    }

    /**
     * Creates filter object
     *
     * @param array $attributeData
     * @param \Magento\Framework\View\Element\UiComponent\ContextInterface $context
     * @return \Magento\Ui\Component\Listing\Columns\ColumnInterface
     */
    public function create(array $attributeData, $context)
    {
        $config = [
            'dataScope' => $attributeData[AttributeMetadata::ATTRIBUTE_CODE],
            'label' => __($attributeData[AttributeMetadata::FRONTEND_LABEL]),
            '__disableTmpl' => 'true'
        ];
        if ($attributeData[AttributeMetadata::OPTIONS]) {
            $config['options'] = $attributeData[AttributeMetadata::OPTIONS];
            $config['caption'] = __('Select...');
        }
        $arguments = [
            'data' => [
                'config' => $config,
            ],
            'context' => $context,
        ];

        return $this->componentFactory->create(
            $attributeData[AttributeMetadata::ATTRIBUTE_CODE],
            $this->getFilterType($attributeData[AttributeMetadata::FRONTEND_INPUT]),
            $arguments
        );
    }

    /**
     * Returns filter type
     *
     * @param string $frontendInput
     * @return string
     */
    protected function getFilterType($frontendInput)
    {
        return isset($this->filterMap[$frontendInput]) ? $this->filterMap[$frontendInput] : $this->filterMap['default'];
    }
}
