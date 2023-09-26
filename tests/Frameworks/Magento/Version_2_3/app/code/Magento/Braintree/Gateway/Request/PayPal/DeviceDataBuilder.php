<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Braintree\Gateway\Request\PayPal;

use Magento\Braintree\Gateway\SubjectReader;
use Magento\Braintree\Observer\DataAssignObserver;
use Magento\Payment\Gateway\Request\BuilderInterface;

/**
 * Class DeviceDataBuilder
 *
 * @deprecated Starting from Magento 2.3.6 Braintree payment method core integration is deprecated
 * in favor of official payment integration available on the marketplace
 */
class DeviceDataBuilder implements BuilderInterface
{
    /**
     * @var string
     */
    private static $deviceDataKey = 'deviceData';

    /**
     * @var SubjectReader
     */
    private $subjectReader;

    /**
     * DeviceDataBuilder constructor.
     * @param SubjectReader $subjectReader
     */
    public function __construct(SubjectReader $subjectReader)
    {
        $this->subjectReader = $subjectReader;
    }

    /**
     * @inheritdoc
     */
    public function build(array $buildSubject)
    {
        $result = [];
        $paymentDO = $this->subjectReader->readPayment($buildSubject);

        $payment = $paymentDO->getPayment();
        $data = $payment->getAdditionalInformation();
        if (!empty($data[DataAssignObserver::DEVICE_DATA])) {
            $result[self::$deviceDataKey] = $data[DataAssignObserver::DEVICE_DATA];
        }

        return $result;
    }
}
