<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Paypal\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Paypal\Model\Billing\AgreementFactory;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Paypal\Model\Payment\Method\Billing\AbstractAgreement;

/**
 * Class BillingAgreementConfigProvider
 */
class BillingAgreementConfigProvider implements ConfigProviderInterface
{
    /**
     * @var CurrentCustomer
     */
    protected $currentCustomer;

    /**
     * @var AgreementFactory
     */
    protected $agreementFactory;

    /**
     * @param CurrentCustomer $currentCustomer
     * @param AgreementFactory $agreementFactory
     */
    public function __construct(
        CurrentCustomer $currentCustomer,
        AgreementFactory $agreementFactory
    ) {
        $this->currentCustomer = $currentCustomer;
        $this->agreementFactory = $agreementFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig()
    {
        $config = [
            'payment' => [
                'paypalBillingAgreement' => [
                    'agreements' => $this->getBillingAgreements(),
                    'transportName' => AbstractAgreement::TRANSPORT_BILLING_AGREEMENT_ID
                ]
            ]
        ];

        return $config;
    }

    /**
     * Retrieve available customer billing agreements
     *
     * @return array
     */
    protected function getBillingAgreements()
    {
        $customerId = $this->currentCustomer->getCustomerId();
        $data = [];
        if (!$customerId) {
            return $data;
        }
        $collection = $this->agreementFactory->create()->getAvailableCustomerBillingAgreements(
            $customerId
        );
        foreach ($collection as $item) {
            $data[] = ['id' => $item->getId(), 'referenceId' => $item->getReferenceId()];
        }
        return $data;
    }
}
