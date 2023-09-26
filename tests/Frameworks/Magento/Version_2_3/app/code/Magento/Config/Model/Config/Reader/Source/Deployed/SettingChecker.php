<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Config\Model\Config\Reader\Source\Deployed;

use Magento\Config\Model\Placeholder\PlaceholderFactory;
use Magento\Config\Model\Placeholder\PlaceholderInterface;
use Magento\Framework\App\Config\ScopeCodeResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;

/**
 * Class for checking settings that defined in config file
 * @api
 * @since 100.1.2
 */
class SettingChecker
{
    /**
     * @var DeploymentConfig
     */
    private $config;

    /**
     * @var PlaceholderInterface
     */
    private $placeholder;

    /**
     * @var ScopeCodeResolver
     */
    private $scopeCodeResolver;

    /**
     * @param DeploymentConfig $config
     * @param PlaceholderFactory $placeholderFactory
     * @param ScopeCodeResolver $scopeCodeResolver
     */
    public function __construct(
        DeploymentConfig $config,
        PlaceholderFactory $placeholderFactory,
        ScopeCodeResolver $scopeCodeResolver
    ) {
        $this->config = $config;
        $this->scopeCodeResolver = $scopeCodeResolver;
        $this->placeholder = $placeholderFactory->create(PlaceholderFactory::TYPE_ENVIRONMENT);
    }

    /**
     * Check that setting defined in deployed configuration
     *
     * @param string $path
     * @param string $scope
     * @param string|null $scopeCode
     * @return boolean
     * @since 100.1.2
     */
    public function isReadOnly($path, $scope, $scopeCode = null)
    {
        $config = $this->getEnvValue(
            $this->placeholder->generate($path, $scope, $scopeCode)
        );

        if (null === $config) {
            $config = $this->config->get($this->resolvePath($scope, $scopeCode) . "/" . $path);
        }

        if (null === $config) {
            $config = $this->config->get(
                $this->resolvePath(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null) . "/" . $path
            );
        }

        return $config !== null;
    }

    /**
     * Check that there is value for generated placeholder
     *
     * Placeholder is generated from values of $path, $scope and $scopeCode
     *
     * @param string $path
     * @param string $scope
     * @param string|null $scopeCode
     * @return string|null
     * @since 100.1.2
     */
    public function getPlaceholderValue($path, $scope, $scopeCode = null)
    {
        return $this->getEnvValue($this->placeholder->generate($path, $scope, $scopeCode));
    }

    /**
     * Retrieve value of environment variable by placeholder
     *
     * @param string $placeholder
     * @return string|null
     * @since 100.1.2
     */
    public function getEnvValue($placeholder)
    {
        // phpcs:disable Magento2.Security.Superglobal
        if ($this->placeholder->isApplicable($placeholder) && isset($_ENV[$placeholder])) {
            return $_ENV[$placeholder];
        }
        // phpcs:enable

        return null;
    }

    /**
     * Resolve path by scope and scope code
     *
     * @param string $scope
     * @param string $scopeCode
     * @return string
     */
    private function resolvePath($scope, $scopeCode)
    {
        $scopePath = 'system/' . $scope;

        if ($scope != ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
            $scopePath .= '/' . $this->scopeCodeResolver->resolve($scope, $scopeCode);
        }

        return $scopePath;
    }
}
