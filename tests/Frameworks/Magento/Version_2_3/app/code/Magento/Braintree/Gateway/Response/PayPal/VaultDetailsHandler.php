<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Braintree\Gateway\Response\PayPal;

use Braintree\Transaction;
use Magento\Braintree\Gateway\SubjectReader;
use Magento\Framework\Intl\DateTimeFactory;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Api\Data\OrderPaymentExtensionInterface;
use Magento\Sales\Api\Data\OrderPaymentExtensionInterfaceFactory;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;

/**
 * Vault Details Handler
 *
 * @deprecated Starting from Magento 2.3.6 Braintree payment method core integration is deprecated
 * in favor of official payment integration available on the marketplace
 */
class VaultDetailsHandler implements HandlerInterface
{
    /**
     * @var PaymentTokenFactoryInterface
     */
    private $paymentTokenFactory;

    /**
     * @var OrderPaymentExtensionInterfaceFactory
     */
    private $paymentExtensionFactory;

    /**
     * @var SubjectReader
     */
    private $subjectReader;

    /**
     * @var DateTimeFactory
     */
    private $dateTimeFactory;

    /**
     * @param PaymentTokenFactoryInterface $paymentTokenFactory
     * @param OrderPaymentExtensionInterfaceFactory $paymentExtensionFactory
     * @param SubjectReader $subjectReader
     * @param DateTimeFactory $dateTimeFactory
     */
    public function __construct(
        PaymentTokenFactoryInterface $paymentTokenFactory,
        OrderPaymentExtensionInterfaceFactory $paymentExtensionFactory,
        SubjectReader $subjectReader,
        DateTimeFactory $dateTimeFactory
    ) {
        $this->paymentTokenFactory = $paymentTokenFactory;
        $this->paymentExtensionFactory = $paymentExtensionFactory;
        $this->subjectReader = $subjectReader;
        $this->dateTimeFactory = $dateTimeFactory;
    }

    /**
     * @inheritdoc
     */
    public function handle(array $handlingSubject, array $response)
    {
        $paymentDO = $this->subjectReader->readPayment($handlingSubject);
        $transaction = $this->subjectReader->readTransaction($response);
        $payment = $paymentDO->getPayment();

        // add vault payment token entity to extension attributes
        $paymentToken = $this->getVaultPaymentToken($transaction);
        if ($paymentToken !== null) {
            $extensionAttributes = $this->getExtensionAttributes($payment);
            $extensionAttributes->setVaultPaymentToken($paymentToken);
        }
    }

    /**
     * Get vault payment token entity
     *
     * @param \Braintree\Transaction $transaction
     * @return PaymentTokenInterface|null
     */
    private function getVaultPaymentToken(Transaction $transaction)
    {
        // Check token existing in gateway response
        $token = $transaction->paypalDetails->token;
        if (empty($token)) {
            return null;
        }

        /** @var PaymentTokenInterface $paymentToken */
        $paymentToken = $this->paymentTokenFactory->create(PaymentTokenFactoryInterface::TOKEN_TYPE_ACCOUNT);
        $paymentToken->setGatewayToken($token);
        $paymentToken->setExpiresAt($this->getExpirationDate());
        $details = json_encode([
            'payerEmail' => $transaction->paypalDetails->payerEmail
        ]);
        $paymentToken->setTokenDetails($details);

        return $paymentToken;
    }

    /**
     * @return string
     */
    private function getExpirationDate()
    {
        $expDate = $this->dateTimeFactory->create('now', new \DateTimeZone('UTC'));
        $expDate->add(new \DateInterval('P1Y'));
        return $expDate->format('Y-m-d 00:00:00');
    }

    /**
     * Get payment extension attributes
     * @param InfoInterface $payment
     * @return OrderPaymentExtensionInterface
     */
    private function getExtensionAttributes(InfoInterface $payment)
    {
        $extensionAttributes = $payment->getExtensionAttributes();
        if ($extensionAttributes === null) {
            $extensionAttributes = $this->paymentExtensionFactory->create();
            $payment->setExtensionAttributes($extensionAttributes);
        }
        return $extensionAttributes;
    }
}
