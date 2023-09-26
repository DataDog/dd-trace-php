<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Signifyd\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Signifyd integration configuration.
 *
 * Class is a proxy service for retrieving configuration settings.
 *
 * @deprecated 100.3.5 Starting from Magento 2.3.5 Signifyd core integration is deprecated in favor of
 * official Signifyd integration available on the marketplace
 */
class Config
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * Config constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * If this config option set to false no Signifyd integration should be available
     * (only possibility to configure Signifyd setting in admin)
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isActive($storeId = null): bool
    {
        $enabled = $this->scopeConfig->isSetFlag(
            'fraud_protection/signifyd/active',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $enabled;
    }

    /**
     * Signifyd API Key used for authentication.
     *
     * @see https://www.signifyd.com/docs/api/#/introduction/authentication
     * @see https://app.signifyd.com/settings
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiKey($storeId = null): string
    {
        $apiKey = $this->scopeConfig->getValue(
            'fraud_protection/signifyd/api_key',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $apiKey;
    }

    /**
     * Base URL to Signifyd REST API.
     * Usually equals to https://api.signifyd.com/v2 and should not be changed
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiUrl($storeId = null): string
    {
        $apiUrl = $this->scopeConfig->getValue(
            'fraud_protection/signifyd/api_url',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $apiUrl;
    }

    /**
     * If is "true" extra information about interaction with Signifyd API are written to debug.log file
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isDebugModeEnabled($storeId = null): bool
    {
        $debugModeEnabled = $this->scopeConfig->isSetFlag(
            'fraud_protection/signifyd/debug',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $debugModeEnabled;
    }
}
