<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Paypal\Helper;

use Magento\Framework\App\ObjectManager;
use Magento\Payment\Api\Data\PaymentMethodInterface;
use Magento\Payment\Api\PaymentMethodListInterface;
use Magento\Payment\Model\Method\InstanceFactory;
use Magento\Payment\Model\MethodInterface;
use Magento\Paypal\Model\Billing\Agreement\MethodInterface as BillingAgreementMethodInterface;

/**
 * Paypal Data helper
 */
class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    const HTML_TRANSACTION_ID =
        '<a target="_blank" href="https://www%1$s.paypal.com/cgi-bin/webscr?cmd=_view-a-trans&id=%2$s">%2$s</a>';

    /**
     * Cache for shouldAskToCreateBillingAgreement()
     *
     * @var bool
     */
    protected static $_shouldAskToCreateBillingAgreement = null;

    /**
     * @var \Magento\Payment\Helper\Data
     */
    protected $_paymentData;

    /**
     * @var \Magento\Paypal\Model\Billing\AgreementFactory
     */
    protected $_agreementFactory;

    /**
     * @var array
     */
    private $methodCodes;

    /**
     * @var \Magento\Paypal\Model\ConfigFactory
     */
    private $configFactory;

    /**
     * @var PaymentMethodListInterface
     */
    private $paymentMethodList;

    /**
     * @var InstanceFactory
     */
    private $paymentMethodInstanceFactory;

    /**
     * @param \Magento\Framework\App\Helper\Context $context
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Paypal\Model\Billing\AgreementFactory $agreementFactory
     * @param \Magento\Paypal\Model\ConfigFactory $configFactory
     * @param array $methodCodes
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Paypal\Model\Billing\AgreementFactory $agreementFactory,
        \Magento\Paypal\Model\ConfigFactory $configFactory,
        array $methodCodes
    ) {
        $this->_paymentData = $paymentData;
        $this->_agreementFactory = $agreementFactory;
        $this->methodCodes = $methodCodes;
        $this->configFactory = $configFactory;
        parent::__construct($context);
    }

    /**
     * Check whether customer should be asked confirmation whether to sign a billing agreement
     *
     * @param \Magento\Paypal\Model\Config $config
     * @param int $customerId
     * @return bool
     */
    public function shouldAskToCreateBillingAgreement(\Magento\Paypal\Model\Config $config, $customerId)
    {
        if (null === self::$_shouldAskToCreateBillingAgreement) {
            self::$_shouldAskToCreateBillingAgreement = false;
            if ($customerId && $config->shouldAskToCreateBillingAgreement()) {
                if ($this->_agreementFactory->create()->needToCreateForCustomer($customerId)) {
                    self::$_shouldAskToCreateBillingAgreement = true;
                }
            }
        }
        return self::$_shouldAskToCreateBillingAgreement;
    }

    /**
     * Retrieve available billing agreement methods
     *
     * @param null|string|bool|int|\Magento\Store\Model\Store $store
     * @param \Magento\Quote\Model\Quote|null $quote
     * @return BillingAgreementMethodInterface[]
     */
    public function getBillingAgreementMethods($store = null, $quote = null)
    {
        $activeMethods = array_map(
            function (PaymentMethodInterface $method) {
                return $this->getPaymentMethodInstanceFactory()->create($method);
            },
            $this->getPaymentMethodList()->getActiveList($store)
        );

        $result = array_filter(
            $activeMethods,
            function (MethodInterface $method) use ($quote) {
                return $method instanceof BillingAgreementMethodInterface && $method->isAvailable($quote);
            }
        );

        return $result;
    }

    /**
     * Get HTML representation of transaction id
     *
     * @param string $methodCode
     * @param string $txnId
     * @return string
     */
    public function getHtmlTransactionId($methodCode, $txnId)
    {
        if (in_array($methodCode, $this->methodCodes)) {
            /** @var \Magento\Paypal\Model\Config $config */
            $config = $this->configFactory->create()->setMethod($methodCode);
            $sandboxFlag = ($config->getValue('sandboxFlag') ? '.sandbox' : '');
            return sprintf(self::HTML_TRANSACTION_ID, $sandboxFlag, $txnId);
        }
        return $txnId;
    }

    /**
     * Get payment method list.
     *
     * @return PaymentMethodListInterface
     * @deprecated 100.2.0
     */
    private function getPaymentMethodList()
    {
        if ($this->paymentMethodList === null) {
            $this->paymentMethodList = ObjectManager::getInstance()->get(
                PaymentMethodListInterface::class
            );
        }
        return $this->paymentMethodList;
    }

    /**
     * Get payment method instance factory.
     *
     * @return InstanceFactory
     * @deprecated 100.2.0
     */
    private function getPaymentMethodInstanceFactory()
    {
        if ($this->paymentMethodInstanceFactory === null) {
            $this->paymentMethodInstanceFactory = ObjectManager::getInstance()->get(
                InstanceFactory::class
            );
        }
        return $this->paymentMethodInstanceFactory;
    }
}
