<?php
/**
 * Google AdWords Conversion Id Backend model
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\GoogleAdwords\Model\Config\Backend;

/**
 * @api
 * @since 100.0.2
 */
class ConversionId extends \Magento\GoogleAdwords\Model\Config\Backend\AbstractConversion
{
    /**
     * Validation rule conversion id
     *
     * @return \Zend_Validate_Interface|null
     */
    protected function _getValidationRulesBeforeSave()
    {
        $this->_validatorComposite->addRule(
            $this->_validatorFactory->createConversionIdValidator($this->getValue()),
            'conversion_id'
        );
        return $this->_validatorComposite;
    }

    /**
     * Get tested value
     *
     * @return string
     */
    public function getConversionId()
    {
        return $this->getValue();
    }
}
