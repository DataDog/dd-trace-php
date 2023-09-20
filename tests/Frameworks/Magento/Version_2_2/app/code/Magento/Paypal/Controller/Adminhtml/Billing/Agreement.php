<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Paypal\Controller\Adminhtml\Billing;

/**
 * Adminhtml billing agreement controller
 */
abstract class Agreement extends \Magento\Backend\App\Action
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Magento_Paypal::billing_agreement';

    /**
     * Core registry
     *
     * @var \Magento\Framework\Registry
     */
    protected $_coreRegistry = null;

    /**
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Framework\Registry $coreRegistry
     */
    public function __construct(\Magento\Backend\App\Action\Context $context, \Magento\Framework\Registry $coreRegistry)
    {
        $this->_coreRegistry = $coreRegistry;
        parent::__construct($context);
    }

    /**
     * Initialize billing agreement by ID specified in request
     *
     * @return \Magento\Paypal\Model\Billing\Agreement|false
     */
    protected function _initBillingAgreement()
    {
        $agreementId = $this->getRequest()->getParam('agreement');
        $agreementModel = $this->_objectManager->create(
            \Magento\Paypal\Model\Billing\Agreement::class
        )->load($agreementId);

        if (!$agreementModel->getId()) {
            $this->messageManager->addErrorMessage(
                __('Please specify the correct billing agreement ID and try again.')
            );
            return false;
        }

        $this->_coreRegistry->register('current_billing_agreement', $agreementModel);
        return $agreementModel;
    }
}
