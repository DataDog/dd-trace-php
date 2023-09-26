<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Braintree\Gateway\Request;

use Magento\Braintree\Gateway\Config\Config;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Braintree\Gateway\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Helper\Formatter;

/**
 * Class ThreeDSecureDataBuilder
 *
 * @deprecated Starting from Magento 2.3.6 Braintree payment method core integration is deprecated
 * in favor of official payment integration available on the marketplace
 */
class ThreeDSecureDataBuilder implements BuilderInterface
{
    use Formatter;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var SubjectReader
     */
    private $subjectReader;

    /**
     * Constructor
     *
     * @param Config $config
     * @param SubjectReader $subjectReader
     */
    public function __construct(Config $config, SubjectReader $subjectReader)
    {
        $this->config = $config;
        $this->subjectReader = $subjectReader;
    }

    /**
     * @inheritdoc
     */
    public function build(array $buildSubject)
    {
        $result = [];

        $paymentDO = $this->subjectReader->readPayment($buildSubject);
        $amount = $this->formatPrice($this->subjectReader->readAmount($buildSubject));

        if ($this->is3DSecureEnabled($paymentDO->getOrder(), $amount)) {
            $result['options'][Config::CODE_3DSECURE] = ['required' => true];
        }
        return $result;
    }

    /**
     * Check if 3d secure is enabled
     * @param OrderAdapterInterface $order
     * @param float $amount
     * @return bool
     */
    private function is3DSecureEnabled(OrderAdapterInterface $order, $amount)
    {
        $storeId = $order->getStoreId();
        if (!$this->config->isVerify3DSecure($storeId)
            || $amount < $this->config->getThresholdAmount($storeId)
        ) {
            return false;
        }

        $billingAddress = $order->getBillingAddress();
        $specificCounties = $this->config->get3DSecureSpecificCountries($storeId);
        if (!empty($specificCounties) && !in_array($billingAddress->getCountryId(), $specificCounties)) {
            return false;
        }

        return true;
    }
}
