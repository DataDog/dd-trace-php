<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Ui\Component\Listing\Column;

use Magento\Customer\Api\Data\ValidationRuleInterface;

/**
 * Provides validation classes according to corresponding rules.
 */
class ValidationRules
{
    /**
     * @var array
     */
    protected $inputValidationMap = [
        'alpha' => 'validate-alpha',
        'numeric' => 'validate-number',
        'alphanumeric' => 'validate-alphanum',
        'alphanum-with-spaces' => 'validate-alphanum-with-spaces',
        'url' => 'validate-url',
        'email' => 'validate-email',
    ];

    /**
     * Return list of validation rules with their value
     *
     * @param boolean $isRequired
     * @param array $validationRules
     * @return array
     */
    public function getValidationRules($isRequired, $validationRules)
    {
        $rules = [];
        if ($isRequired) {
            $rules['required-entry'] = true;
        }
        if (empty($validationRules)) {
            return $rules;
        }
        /** @var ValidationRuleInterface $rule */
        foreach ($validationRules as $rule) {
            if (!$rule instanceof ValidationRuleInterface) {
                continue;
            }
            $validationClass = $this->getValidationClass($rule);
            if ($validationClass) {
                $rules[$validationClass] = $this->getRuleValue($rule);
            }
        }

        return $rules;
    }

    /**
     * Return validation class based on rule name or value
     *
     * @param ValidationRuleInterface $rule
     * @return string
     */
    protected function getValidationClass(ValidationRuleInterface $rule)
    {
        $key = $rule->getName() == 'input_validation' ? $rule->getValue() : $rule->getName();
        return $this->inputValidationMap[$key] ?? $key;
    }

    /**
     * Return rule value
     *
     * @param ValidationRuleInterface $rule
     * @return bool|string
     */
    protected function getRuleValue(ValidationRuleInterface $rule)
    {
        return $rule->getName() != 'input_validation' ? $rule->getValue() : true;
    }
}
