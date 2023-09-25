<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Developer\Model\Logger\Handler;

use Magento\Config\Setup\ConfigOptionsList;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;

/**
 * Enable/disable syslog logging based on the deployment config setting.
 */
class Syslog extends \Magento\Framework\Logger\Handler\Syslog
{
    /**
     * @deprecated configuration value has been removed.
     */
    public const CONFIG_PATH = 'dev/syslog/syslog_logging';

    /**
     * Deployment config.
     *
     * @var DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig Scope config
     * @param DeploymentConfig $deploymentConfig Deployment config
     * @param string $ident The string ident to be added to each message
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        DeploymentConfig $deploymentConfig,
        string $ident
    ) {
        parent::__construct($ident);
        $this->deploymentConfig = $deploymentConfig;
    }

    /**
     * @inheritdoc
     */
    public function isHandling(array $record): bool
    {
        return parent::isHandling($record)
            && $this->deploymentConfig->isDbAvailable()
            && $this->isLoggingEnabled();
    }

    /**
     * Check that logging functionality is enabled.
     *
     * @return bool
     */
    private function isLoggingEnabled(): bool
    {
        $configValue = $this->deploymentConfig->get(ConfigOptionsList::CONFIG_PATH_SYSLOG_LOGGING);
        return (bool)$configValue;
    }
}
