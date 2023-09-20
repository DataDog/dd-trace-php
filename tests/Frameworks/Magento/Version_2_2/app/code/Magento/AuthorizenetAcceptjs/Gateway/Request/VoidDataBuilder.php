<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\AuthorizenetAcceptjs\Gateway\Request;

use Magento\AuthorizenetAcceptjs\Gateway\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Model\Order\Payment;

/**
 * Adds the meta transaction information to the request
 *
 * @deprecated Starting from Magento 2.2.11 Authorize.net payment method core integration is deprecated in favor of
 * official payment integration available on the marketplace
 */
class VoidDataBuilder implements BuilderInterface
{
    /**
     * @var string
     */
    private static $requestTypeVoid = 'voidTransaction';

    /**
     * @var SubjectReader
     */
    private $subjectReader;

    /**
     * @param SubjectReader $subjectReader
     */
    public function __construct(
        SubjectReader $subjectReader
    ) {
        $this->subjectReader = $subjectReader;
    }

    /**
     * @inheritdoc
     */
    public function build(array $buildSubject)
    {
        $paymentDO = $this->subjectReader->readPayment($buildSubject);
        $payment = $paymentDO->getPayment();
        $transactionData = [];

        if ($payment instanceof Payment) {
            $authorizationTransaction = $payment->getAuthorizationTransaction();
            $refId = $authorizationTransaction->getAdditionalInformation('real_transaction_id');
            if (empty($refId)) {
                $refId = $authorizationTransaction->getParentTxnId();
            }

            $transactionData['transactionRequest'] = [
                'transactionType' => self::$requestTypeVoid,
                'refTransId' => $refId,
            ];
        }

        return $transactionData;
    }
}
