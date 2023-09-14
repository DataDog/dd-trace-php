<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Deploy\Model;

use Magento\Config\App\Config\Type\System;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\App\DeploymentConfig\Writer;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Config\File\ConfigFilePool;
use Magento\Framework\Stdlib\ArrayManager;
use Magento\Config\Model\PreparedValueFactory;

/**
 * Class ConfigWriter. Save configuration values into config file.
 */
class ConfigWriter
{
    /**
     * @var Writer
     */
    private $writer;

    /**
     * @var ArrayManager
     */
    private $arrayManager;

    /**
     * Creates a prepared instance of Value.
     *
     * @var PreparedValueFactory
     */
    private $preparedValueFactory;

    /**
     * @param Writer $writer
     * @param ArrayManager $arrayManager
     * @param PreparedValueFactory|null $valueFactory Creates a prepared instance of Value
     */
    public function __construct(
        Writer $writer,
        ArrayManager $arrayManager,
        PreparedValueFactory $valueFactory = null
    ) {
        $this->writer = $writer;
        $this->arrayManager = $arrayManager;
        $this->preparedValueFactory = $valueFactory ?: ObjectManager::getInstance()->get(PreparedValueFactory::class);
    }

    /**
     * Save given list of configuration values into config file.
     *
     * @param array $values the configuration values (path-value pairs) to be saved.
     * @param string $scope scope in which configuration would be saved.
     * @param string|null $scopeCode
     * @return void
     */
    public function save(array $values, $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        $config = [];
        $pathPrefix = $this->getPathPrefix($scope, $scopeCode);

        $values = array_filter(
            $values,
            function ($value) {
                return $value !== null;
            }
        );

        foreach ($values as $configPath => $configValue) {
            $fullConfigPath = $pathPrefix . $configPath;
            $backendModel = $this->preparedValueFactory->create($configPath, $configValue, $scope, $scopeCode);

            if ($backendModel instanceof Value) {
                $backendModel->validateBeforeSave();
                $backendModel->beforeSave();
                $configValue = $backendModel->getValue();
                $backendModel->afterSave();
            }

            $config = $this->setConfig($config, $fullConfigPath, $configValue);
        }

        $this->writer->saveConfig(
            [ConfigFilePool::APP_ENV => $config]
        );
    }

    /**
     * Apply configuration value into configuration array by given path.
     * Ignore values that equal to null.
     *
     * @param array $config
     * @param string $configPath
     * @param string $configValue
     * @return array
     */
    private function setConfig(array $config, $configPath, $configValue)
    {
        if ($configValue === null) {
            return $config;
        }

        $config = $this->arrayManager->set(
            $configPath,
            $config,
            $configValue
        );

        return $config;
    }

    /**
     * Generate config prefix from given $scope and $scopeCode.
     * If $scope isn't equal to 'default' and $scopeCode isn't empty put $scopeCode into prefix path,
     * otherwise ignore $scopeCode.
     *
     * @param string $scope
     * @param string $scopeCode
     * @return string
     */
    private function getPathPrefix($scope, $scopeCode)
    {
        $pathPrefixes = [System::CONFIG_TYPE, $scope];
        if ($scope !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            && !empty($scopeCode)
        ) {
            $pathPrefixes[] = $scopeCode;
        }

        return implode('/', $pathPrefixes) . '/';
    }
}
