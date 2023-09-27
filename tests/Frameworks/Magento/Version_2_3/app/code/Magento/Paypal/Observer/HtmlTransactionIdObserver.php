<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Paypal\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;

/**
 * PayPal module observer
 */
class HtmlTransactionIdObserver implements ObserverInterface
{
    /**
     * @var \Magento\Paypal\Helper\Data
     */
    private $paypalData;

    /**
     * @param \Magento\Paypal\Helper\Data $paypalData
     */
    public function __construct(
        \Magento\Paypal\Helper\Data $paypalData
    ) {
        $this->paypalData = $paypalData;
    }

    /**
     * Update transaction with HTML representation of txn_id
     *
     * @param EventObserver $observer
     * @return void
     */
    public function execute(EventObserver $observer)
    {
        /** @var \Magento\Sales\Model\Order\Payment\Transaction $transaction */
        $transaction = $observer->getDataObject();
        $order = $transaction->getOrder();
        $payment = $order->getPayment();
        $methodInstance = $payment->getMethodInstance();
        $paymentCode = $methodInstance->getCode();
        $transactionLink = $this->paypalData->getHtmlTransactionId($paymentCode, $transaction->getTxnId());
        $transaction->setData('html_txn_id', $transactionLink);
    }
}
