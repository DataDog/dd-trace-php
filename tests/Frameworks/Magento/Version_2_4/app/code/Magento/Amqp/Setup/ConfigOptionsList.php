<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Amqp\Setup;

use Magento\Framework\Config\Data\ConfigData;
use Magento\Framework\Config\File\ConfigFilePool;
use Magento\Framework\Setup\ConfigOptionsListInterface;
use Magento\Framework\Setup\Option\TextConfigOption;
use Magento\Framework\App\DeploymentConfig;

/**
 * Deployment configuration options needed for Setup application
 */
class ConfigOptionsList implements ConfigOptionsListInterface
{
    /**
     * Input key for the options
     */
    const INPUT_KEY_QUEUE_AMQP_HOST = 'amqp-host';
    const INPUT_KEY_QUEUE_AMQP_PORT = 'amqp-port';
    const INPUT_KEY_QUEUE_AMQP_USER = 'amqp-user';
    const INPUT_KEY_QUEUE_AMQP_PASSWORD = 'amqp-password';
    const INPUT_KEY_QUEUE_AMQP_VIRTUAL_HOST = 'amqp-virtualhost';
    const INPUT_KEY_QUEUE_AMQP_SSL = 'amqp-ssl';
    const INPUT_KEY_QUEUE_AMQP_SSL_OPTIONS = 'amqp-ssl-options';

    /**
     * Path to the values in the deployment config
     */
    const CONFIG_PATH_QUEUE_AMQP_HOST = 'queue/amqp/host';
    const CONFIG_PATH_QUEUE_AMQP_PORT = 'queue/amqp/port';
    const CONFIG_PATH_QUEUE_AMQP_USER = 'queue/amqp/user';
    const CONFIG_PATH_QUEUE_AMQP_PASSWORD = 'queue/amqp/password';
    const CONFIG_PATH_QUEUE_AMQP_VIRTUAL_HOST = 'queue/amqp/virtualhost';
    const CONFIG_PATH_QUEUE_AMQP_SSL = 'queue/amqp/ssl';
    const CONFIG_PATH_QUEUE_AMQP_SSL_OPTIONS = 'queue/amqp/ssl_options';

    /**
     * Default values
     */
    const DEFAULT_AMQP_HOST = '';
    const DEFAULT_AMQP_PORT = '5672';
    const DEFAULT_AMQP_USER = '';
    const DEFAULT_AMQP_PASSWORD = '';
    const DEFAULT_AMQP_VIRTUAL_HOST = '/';
    const DEFAULT_AMQP_SSL = '';

    /**
     * @var ConnectionValidator
     */
    private $connectionValidator;

    /**
     * Constructor
     *
     * @param ConnectionValidator $connectionValidator
     */
    public function __construct(ConnectionValidator $connectionValidator)
    {
        $this->connectionValidator = $connectionValidator;
    }

    /**
     * {@inheritdoc}
     */
    public function getOptions()
    {
        return [
            new TextConfigOption(
                self::INPUT_KEY_QUEUE_AMQP_HOST,
                TextConfigOption::FRONTEND_WIZARD_TEXT,
                self::CONFIG_PATH_QUEUE_AMQP_HOST,
                'Amqp server host',
                self::DEFAULT_AMQP_HOST
            ),
            new TextConfigOption(
                self::INPUT_KEY_QUEUE_AMQP_PORT,
                TextConfigOption::FRONTEND_WIZARD_TEXT,
                self::CONFIG_PATH_QUEUE_AMQP_PORT,
                'Amqp server port',
                self::DEFAULT_AMQP_PORT
            ),
            new TextConfigOption(
                self::INPUT_KEY_QUEUE_AMQP_USER,
                TextConfigOption::FRONTEND_WIZARD_TEXT,
                self::CONFIG_PATH_QUEUE_AMQP_USER,
                'Amqp server username',
                self::DEFAULT_AMQP_USER
            ),
            new TextConfigOption(
                self::INPUT_KEY_QUEUE_AMQP_PASSWORD,
                TextConfigOption::FRONTEND_WIZARD_TEXT,
                self::CONFIG_PATH_QUEUE_AMQP_PASSWORD,
                'Amqp server password',
                self::DEFAULT_AMQP_PASSWORD
            ),
            new TextConfigOption(
                self::INPUT_KEY_QUEUE_AMQP_VIRTUAL_HOST,
                TextConfigOption::FRONTEND_WIZARD_TEXT,
                self::CONFIG_PATH_QUEUE_AMQP_VIRTUAL_HOST,
                'Amqp virtualhost',
                self::DEFAULT_AMQP_VIRTUAL_HOST
            ),
            new TextConfigOption(
                self::INPUT_KEY_QUEUE_AMQP_SSL,
                TextConfigOption::FRONTEND_WIZARD_TEXT,
                self::CONFIG_PATH_QUEUE_AMQP_SSL,
                'Amqp SSL',
                self::DEFAULT_AMQP_SSL
            ),
            new TextConfigOption(
                self::INPUT_KEY_QUEUE_AMQP_SSL_OPTIONS,
                TextConfigOption::FRONTEND_WIZARD_TEXTAREA,
                self::CONFIG_PATH_QUEUE_AMQP_SSL_OPTIONS,
                'Amqp SSL Options (JSON)',
                self::DEFAULT_AMQP_SSL
            ),
        ];
    }

    /**
     * {@inheritdoc}
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function createConfig(array $data, DeploymentConfig $deploymentConfig)
    {
        $configData = new ConfigData(ConfigFilePool::APP_ENV);

        if (!$this->isDataEmpty($data, self::INPUT_KEY_QUEUE_AMQP_HOST)) {
            $configData->set(self::CONFIG_PATH_QUEUE_AMQP_HOST, $data[self::INPUT_KEY_QUEUE_AMQP_HOST]);
            if (!$this->isDataEmpty($data, self::INPUT_KEY_QUEUE_AMQP_PORT)) {
                $configData->set(self::CONFIG_PATH_QUEUE_AMQP_PORT, $data[self::INPUT_KEY_QUEUE_AMQP_PORT]);
            }
            if (!$this->isDataEmpty($data, self::INPUT_KEY_QUEUE_AMQP_USER)) {
                $configData->set(self::CONFIG_PATH_QUEUE_AMQP_USER, $data[self::INPUT_KEY_QUEUE_AMQP_USER]);
            }
            if (!$this->isDataEmpty($data, self::INPUT_KEY_QUEUE_AMQP_PASSWORD)) {
                $configData->set(self::CONFIG_PATH_QUEUE_AMQP_PASSWORD, $data[self::INPUT_KEY_QUEUE_AMQP_PASSWORD]);
            }
            if (!$this->isDataEmpty($data, self::INPUT_KEY_QUEUE_AMQP_VIRTUAL_HOST)) {
                $configData->set(
                    self::CONFIG_PATH_QUEUE_AMQP_VIRTUAL_HOST,
                    $data[self::INPUT_KEY_QUEUE_AMQP_VIRTUAL_HOST]
                );
            }
            if (!$this->isDataEmpty($data, self::INPUT_KEY_QUEUE_AMQP_SSL)) {
                $configData->set(self::CONFIG_PATH_QUEUE_AMQP_SSL, $data[self::INPUT_KEY_QUEUE_AMQP_SSL]);
            }
            if (!$this->isDataEmpty(
                $data,
                self::INPUT_KEY_QUEUE_AMQP_SSL_OPTIONS
            )) {
                $options = json_decode(
                    $data[self::INPUT_KEY_QUEUE_AMQP_SSL_OPTIONS],
                    true
                );
                if ($options !== null) {
                    $configData->set(
                        self::CONFIG_PATH_QUEUE_AMQP_SSL_OPTIONS,
                        $options
                    );
                }
            }
        }

        return [$configData];
    }

    /**
     * {@inheritdoc}
     */
    public function validate(array $options, DeploymentConfig $deploymentConfig)
    {
        $errors = [];

        if (isset($options[self::INPUT_KEY_QUEUE_AMQP_HOST])
            && $options[self::INPUT_KEY_QUEUE_AMQP_HOST] !== '') {
            if (!$this->isDataEmpty(
                $options,
                self::INPUT_KEY_QUEUE_AMQP_SSL_OPTIONS
            )) {
                $sslOptions = json_decode(
                    $options[self::INPUT_KEY_QUEUE_AMQP_SSL_OPTIONS],
                    true
                );
            } else {
                $sslOptions = null;
            }
            $isSslEnabled = !empty($options[self::INPUT_KEY_QUEUE_AMQP_SSL])
                && $options[self::INPUT_KEY_QUEUE_AMQP_SSL] !== 'false';

            $result = $this->connectionValidator->isConnectionValid(
                $options[self::INPUT_KEY_QUEUE_AMQP_HOST],
                $options[self::INPUT_KEY_QUEUE_AMQP_PORT],
                $options[self::INPUT_KEY_QUEUE_AMQP_USER],
                $options[self::INPUT_KEY_QUEUE_AMQP_PASSWORD],
                $options[self::INPUT_KEY_QUEUE_AMQP_VIRTUAL_HOST],
                $isSslEnabled,
                $sslOptions
            );

            if (!$result) {
                $errors[] = "Could not connect to the Amqp Server.";
            }
        }

        return $errors;
    }

    /**
     * Check if data ($data) with key ($key) is empty
     *
     * @param array $data
     * @param string $key
     * @return bool
     */
    private function isDataEmpty(array $data, $key)
    {
        if (isset($data[$key]) && $data[$key] !== '') {
            return false;
        }

        return true;
    }
}
