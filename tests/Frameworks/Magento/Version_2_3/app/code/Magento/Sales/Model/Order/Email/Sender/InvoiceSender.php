<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Model\Order\Email\Sender;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address\Renderer;
use Magento\Sales\Model\Order\Email\Container\InvoiceIdentity;
use Magento\Sales\Model\Order\Email\Container\Template;
use Magento\Sales\Model\Order\Email\Sender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\ResourceModel\Order\Invoice as InvoiceResource;

/**
 * Sends order invoice email to the customer.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class InvoiceSender extends Sender
{
    /**
     * @var PaymentHelper
     */
    protected $paymentHelper;

    /**
     * @var InvoiceResource
     */
    protected $invoiceResource;

    /**
     * Global configuration storage.
     *
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $globalConfig;

    /**
     * @var Renderer
     */
    protected $addressRenderer;

    /**
     * Application Event Dispatcher
     *
     * @var ManagerInterface
     */
    protected $eventManager;

    /**
     * @param Template $templateContainer
     * @param InvoiceIdentity $identityContainer
     * @param Order\Email\SenderBuilderFactory $senderBuilderFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param Renderer $addressRenderer
     * @param PaymentHelper $paymentHelper
     * @param InvoiceResource $invoiceResource
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $globalConfig
     * @param ManagerInterface $eventManager
     */
    public function __construct(
        Template $templateContainer,
        InvoiceIdentity $identityContainer,
        \Magento\Sales\Model\Order\Email\SenderBuilderFactory $senderBuilderFactory,
        \Psr\Log\LoggerInterface $logger,
        Renderer $addressRenderer,
        PaymentHelper $paymentHelper,
        InvoiceResource $invoiceResource,
        \Magento\Framework\App\Config\ScopeConfigInterface $globalConfig,
        ManagerInterface $eventManager
    ) {
        parent::__construct($templateContainer, $identityContainer, $senderBuilderFactory, $logger, $addressRenderer);
        $this->paymentHelper = $paymentHelper;
        $this->invoiceResource = $invoiceResource;
        $this->globalConfig = $globalConfig;
        $this->addressRenderer = $addressRenderer;
        $this->eventManager = $eventManager;
    }

    /**
     * Sends order invoice email to the customer.
     *
     * Email will be sent immediately in two cases:
     *
     * - if asynchronous email sending is disabled in global settings
     * - if $forceSyncMode parameter is set to TRUE
     *
     * Otherwise, email will be sent later during running of
     * corresponding cron job.
     *
     * @param Invoice $invoice
     * @param bool $forceSyncMode
     * @return bool
     * @throws \Exception
     */
    public function send(Invoice $invoice, $forceSyncMode = false)
    {
        $invoice->setSendEmail($this->identityContainer->isEnabled());

        if (!$this->globalConfig->getValue('sales_email/general/async_sending') || $forceSyncMode) {
            $order = $invoice->getOrder();
            $this->identityContainer->setStore($order->getStore());

            if ($this->checkIfPartialInvoice($order, $invoice)) {
                $order->setBaseSubtotal((float) $invoice->getBaseSubtotal());
                $order->setBaseTaxAmount((float) $invoice->getBaseTaxAmount());
                $order->setBaseShippingAmount((float) $invoice->getBaseShippingAmount());
            }

            $transport = [
                'order' => $order,
                'order_id' => $order->getId(),
                'invoice' => $invoice,
                'invoice_id' => $invoice->getId(),
                'comment' => $invoice->getCustomerNoteNotify() ? $invoice->getCustomerNote() : '',
                'billing' => $order->getBillingAddress(),
                'payment_html' => $this->getPaymentHtml($order),
                'store' => $order->getStore(),
                'formattedShippingAddress' => $this->getFormattedShippingAddress($order),
                'formattedBillingAddress' => $this->getFormattedBillingAddress($order),
                'order_data' => [
                    'customer_name' => $order->getCustomerName(),
                    'is_not_virtual' => $order->getIsNotVirtual(),
                    'email_customer_note' => $order->getEmailCustomerNote(),
                    'frontend_status_label' => $order->getFrontendStatusLabel()
                ]
            ];
            $transportObject = new DataObject($transport);

            /**
             * Event argument `transport` is @deprecated. Use `transportObject` instead.
             */
            $this->eventManager->dispatch(
                'email_invoice_set_template_vars_before',
                ['sender' => $this, 'transport' => $transportObject->getData(), 'transportObject' => $transportObject]
            );

            $this->templateContainer->setTemplateVars($transportObject->getData());

            if ($this->checkAndSend($order)) {
                $invoice->setEmailSent(true);
                $this->invoiceResource->saveAttribute($invoice, ['send_email', 'email_sent']);
                return true;
            }
        } else {
            $invoice->setEmailSent(null);
            $this->invoiceResource->saveAttribute($invoice, 'email_sent');
        }

        $this->invoiceResource->saveAttribute($invoice, 'send_email');

        return false;
    }

    /**
     * Return payment info block as html
     *
     * @param Order $order
     * @return string
     * @throws \Exception
     */
    protected function getPaymentHtml(Order $order)
    {
        return $this->paymentHelper->getInfoBlockHtml(
            $order->getPayment(),
            $this->identityContainer->getStore()->getStoreId()
        );
    }

    /**
     * Check if the order contains partial invoice
     *
     * @param Order $order
     * @param Invoice $invoice
     * @return bool
     */
    private function checkIfPartialInvoice(Order $order, Invoice $invoice): bool
    {
        $totalQtyOrdered = (float) $order->getTotalQtyOrdered();
        $totalQtyInvoiced = (float) $invoice->getTotalQty();
        return $totalQtyOrdered !== $totalQtyInvoiced;
    }
}
