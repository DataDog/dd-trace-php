<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Model\Order\Email;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Container\IdentityInterface;
use Magento\Sales\Model\Order\Email\Container\Template;
use Magento\Sales\Model\Order\Address\Renderer;

/**
 * Class Sender
 *
 * phpcs:disable Magento2.Classes.AbstractApi
 * @api
 * @since 100.0.2
 */
abstract class Sender
{
    /**
     * @var \Magento\Sales\Model\Order\Email\SenderBuilderFactory
     */
    protected $senderBuilderFactory;

    /**
     * @var Template
     */
    protected $templateContainer;

    /**
     * @var IdentityInterface
     */
    protected $identityContainer;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var Renderer
     */
    protected $addressRenderer;

    /**
     * @param Template $templateContainer
     * @param IdentityInterface $identityContainer
     * @param SenderBuilderFactory $senderBuilderFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param Renderer $addressRenderer
     */
    public function __construct(
        Template $templateContainer,
        IdentityInterface $identityContainer,
        \Magento\Sales\Model\Order\Email\SenderBuilderFactory $senderBuilderFactory,
        \Psr\Log\LoggerInterface $logger,
        Renderer $addressRenderer
    ) {
        $this->templateContainer = $templateContainer;
        $this->identityContainer = $identityContainer;
        $this->senderBuilderFactory = $senderBuilderFactory;
        $this->logger = $logger;
        $this->addressRenderer = $addressRenderer;
    }

    /**
     * Send order email if it is enabled in configuration.
     *
     * @param Order $order
     * @return bool
     */
    protected function checkAndSend(Order $order)
    {
        $this->identityContainer->setStore($order->getStore());
        if (!$this->identityContainer->isEnabled()) {
            return false;
        }
        $this->prepareTemplate($order);

        /** @var SenderBuilder $sender */
        $sender = $this->getSender();

        try {
            $sender->send();
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return false;
        }
        if ($this->identityContainer->getCopyMethod() == 'copy') {
            try {
                $sender->sendCopyTo();
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
            }
        }
        return true;
    }

    /**
     * Populate order email template with customer information.
     *
     * @param Order $order
     * @return void
     */
    protected function prepareTemplate(Order $order)
    {
        $this->templateContainer->setTemplateOptions($this->getTemplateOptions());

        if ($order->getCustomerIsGuest()) {
            $templateId = $this->identityContainer->getGuestTemplateId();
            $customerName = $order->getBillingAddress()->getName();
        } else {
            $templateId = $this->identityContainer->getTemplateId();
            $customerName = $order->getCustomerName();
        }

        $this->identityContainer->setCustomerName($customerName);
        $this->identityContainer->setCustomerEmail($order->getCustomerEmail());
        $this->templateContainer->setTemplateId($templateId);
    }

    /**
     * Create Sender object using appropriate template and identity.
     *
     * @return Sender
     */
    protected function getSender()
    {
        return $this->senderBuilderFactory->create(
            [
                'templateContainer' => $this->templateContainer,
                'identityContainer' => $this->identityContainer,
            ]
        );
    }

    /**
     * Get template options.
     *
     * @return array
     */
    protected function getTemplateOptions()
    {
        return [
            'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
            'store' => $this->identityContainer->getStore()->getStoreId()
        ];
    }

    /**
     * Render shipping address into html.
     *
     * @param Order $order
     * @return string|null
     */
    protected function getFormattedShippingAddress($order)
    {
        return $order->getIsVirtual()
            ? null
            : $this->addressRenderer->format($order->getShippingAddress(), 'html');
    }

    /**
     * Render billing address into html.
     *
     * @param Order $order
     * @return string|null
     */
    protected function getFormattedBillingAddress($order)
    {
        return $this->addressRenderer->format($order->getBillingAddress(), 'html');
    }
}
