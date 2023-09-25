<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Tax\Model\System\Message\Notification;

/**
 * This class allows to display notification in the admin panel about possible discount errors.
 *
 * Discount errors may be caused by tax settings misconfiguration.
 */
class DiscountErrors implements \Magento\Tax\Model\System\Message\NotificationInterface
{
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    private $urlBuilder;

    /**
     * @var \Magento\Tax\Model\Config
     */
    private $taxConfig;

    /**
     * Websites with invalid discount settings
     *
     * @var array
     */
    private $storesWithInvalidSettings;

    /**
     * Initialize dependencies
     *
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \Magento\Tax\Model\Config $taxConfig
     */
    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Tax\Model\Config $taxConfig
    ) {
        $this->storeManager = $storeManager;
        $this->urlBuilder = $urlBuilder;
        $this->taxConfig = $taxConfig;
    }

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function getIdentity()
    {
        return 'TAX_NOTIFICATION_DISCOUNT_ERRORS';
    }

    /**
     * {@inheritdoc}
     */
    public function isDisplayed()
    {
        if (!$this->taxConfig->isWrongDiscountSettingsIgnored() && $this->getStoresWithWrongSettings()) {
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getText()
    {
        $messageDetails = '';

        if (!empty($this->getStoresWithWrongSettings()) && !$this->taxConfig->isWrongDiscountSettingsIgnored()) {
            $messageDetails .= '<strong>';
            $messageDetails .= __('With customer tax applied “Before Discount”,'
                . ' the final discount calculation may not match customers’ expectations. ');
            $messageDetails .= '</strong><p>';
            $messageDetails .= __('Store(s) affected: ');
            $messageDetails .= implode(', ', $this->getStoresWithWrongSettings());
            $messageDetails .= '</p><p>';
            $messageDetails .= __(
                'Click on the link to <a href="%1">ignore this notification</a>',
                $this->urlBuilder->getUrl('tax/tax/ignoreTaxNotification', ['section' => 'discount'])
            );
            $messageDetails .= "</p>";
        }
        
        return $messageDetails;
    }

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function getSeverity()
    {
        return self::SEVERITY_CRITICAL;
    }

    /**
     * Check if tax discount settings are compatible
     *
     * Matrix for invalid discount settings is as follows:
     *      Before Discount / Excluding Tax
     *      Before Discount / Including Tax
     *
     * @param null|int|bool|string|\Magento\Store\Model\Store $store $store
     * @return bool
     */
    private function checkSettings($store = null)
    {
        return $this->taxConfig->applyTaxAfterDiscount($store);
    }

    /**
     * Return list of store names where tax discount settings are compatible.
     * Return true if settings are wrong for default store.
     *
     * @return array
     */
    private function getStoresWithWrongSettings()
    {
        if (null !== $this->storesWithInvalidSettings) {
            return $this->storesWithInvalidSettings;
        }
        $this->storesWithInvalidSettings = [];
        $storeCollection = $this->storeManager->getStores(true);
        foreach ($storeCollection as $store) {
            if (!$this->checkSettings($store)) {
                $website = $store->getWebsite();
                $this->storesWithInvalidSettings[] = $website->getName() . ' (' . $store->getName() . ')';
            }
        }
        return $this->storesWithInvalidSettings;
    }
}
