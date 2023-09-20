<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Paypal\Block\Adminhtml\Billing\Agreement\View\Tab;

use Magento\Customer\Api\CustomerRepositoryInterface;

/**
 * Adminhtml billing agreement info tab
 */
class Info extends \Magento\Backend\Block\Template implements \Magento\Backend\Block\Widget\Tab\TabInterface
{
    /**
     * @var string
     */
    protected $_template = 'Magento_Paypal::billing/agreement/view/tab/info.phtml';

    /**
     * Core registry
     *
     * @var \Magento\Framework\Registry
     */
    protected $_coreRegistry = null;

    /**
     * @var CustomerRepositoryInterface
     */
    protected $_customerRepository;

    /**
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param CustomerRepositoryInterface $customerRepository
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Registry $registry,
        CustomerRepositoryInterface $customerRepository,
        array $data = []
    ) {
        $this->_coreRegistry = $registry;
        $this->_customerRepository = $customerRepository;
        parent::__construct($context, $data);
    }

    /**
     * {@inheritdoc}
     */
    public function getTabLabel()
    {
        return __('General Information');
    }

    /**
     * {@inheritdoc}
     */
    public function getTabTitle()
    {
        return __('General Information');
    }

    /**
     * {@inheritdoc}
     */
    public function canShowTab()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isHidden()
    {
        return false;
    }

    /**
     * Retrieve billing agreement model
     *
     * @return \Magento\Paypal\Model\Billing\Agreement
     */
    protected function _getBillingAgreement()
    {
        return $this->_coreRegistry->registry('current_billing_agreement');
    }

    /**
     * Set data to block
     *
     * @return string
     */
    protected function _toHtml()
    {
        $agreement = $this->_getBillingAgreement();
        $this->setReferenceId($agreement->getReferenceId());
        $customerId = $agreement->getCustomerId();
        $customer = $this->_customerRepository->getById($customerId);
        $this->setCustomerEmail($customer->getEmail());
        $this->setCustomerUrl($this->getUrl('customer/index/edit', ['id' => $customerId]));
        $this->setStatus($agreement->getStatusLabel());
        $this->setCreatedAt($this->formatDate($agreement->getCreatedAt(), \IntlDateFormatter::SHORT, true));
        $this->setUpdatedAt(
            $agreement->getUpdatedAt()
            ? $this->formatDate($agreement->getUpdatedAt(), \IntlDateFormatter::SHORT, true)
            : __('N/A')
        );
        return parent::_toHtml();
    }
}
