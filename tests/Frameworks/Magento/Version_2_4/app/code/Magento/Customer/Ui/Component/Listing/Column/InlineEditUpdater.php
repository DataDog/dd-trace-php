<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponentInterface;

class InlineEditUpdater
{
    /**
     * @var \Magento\Customer\Ui\Component\Listing\Column\ValidationRules
     */
    protected $validationRules;

    /**
     * List of frontend inputs that should be editable in grid
     *
     * @var array
     */
    protected $editableFields = [
        'text',
        'boolean',
        'select',
        'date',
    ];

    /**
     * @param ValidationRules $validationRules
     */
    public function __construct(
        ValidationRules $validationRules
    ) {
        $this->validationRules = $validationRules;
    }

    /**
     * Add editor config to component configuration with correct editorType
     *
     * @param UiComponentInterface $column
     * @param string $frontendInput
     * @param array $validationRules
     * @param bool|false $isRequired
     * @return UiComponentInterface
     */
    public function applyEditing(
        UiComponentInterface $column,
        $frontendInput,
        array $validationRules,
        $isRequired = false
    ) {
        if (in_array($frontendInput, $this->editableFields)) {
            $config = $column->getConfiguration();
            if (!(isset($config['editor']) && isset($config['editor']['editorType']))) {
                if (isset($config['editor']) && is_string($config['editor'])) {
                    $editorType = $config['editor'];
                } elseif (isset($config['dataType'])) {
                    $editorType = $config['dataType'];
                } else {
                    $editorType = $frontendInput;
                }

                $config['editor'] = [
                    'editorType' => $editorType
                ];
            }

            $validationRules = $this->validationRules->getValidationRules($isRequired, $validationRules);
            if (!empty($config['editor']['validation'])) {
                $validationRules = array_merge($config['editor']['validation'], $validationRules);
            }
            $config['editor']['validation'] = $validationRules;
            $column->setData('config', $config);
        }
        return $column;
    }
}
