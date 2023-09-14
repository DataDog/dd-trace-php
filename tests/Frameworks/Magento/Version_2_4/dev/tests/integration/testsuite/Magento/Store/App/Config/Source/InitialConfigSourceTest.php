<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Store\App\Config\Source;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\DeploymentConfig\FileReader;
use Magento\Framework\App\DeploymentConfig\Writer;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Config\File\ConfigFilePool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Test that initial scopes config are loaded if database is available
 * @magentoAppIsolation enabled
 */
class InitialConfigSourceTest extends TestCase
{
    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var ConfigFilePool
     */
    private $configFilePool;

    /**
     * @var FileReader
     */
    private $reader;

    /**
     * @var Writer
     */
    private $writer;

    /**
     * @var array
     */
    private $config;

    /**
     * @var array
     */
    private $envConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->reader = $objectManager->get(FileReader::class);
        $this->writer = $objectManager->get(Writer::class);
        $this->filesystem = $objectManager->get(Filesystem::class);
        $this->configFilePool = $objectManager->get(ConfigFilePool::class);
        $this->storeManager = $objectManager->get(StoreManagerInterface::class);
        $this->config = $this->loadConfig();
        $this->envConfig = $this->loadEnvConfig();
        $this->loadDumpConfig();
        $this->storeManager->reinitStores();
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        $this->clearConfig(ConfigFilePool::APP_CONFIG);
        $this->clearConfig(ConfigFilePool::APP_ENV);
        $this->writer->saveConfig([ConfigFilePool::APP_CONFIG => $this->config]);
        $this->writer->saveConfig([ConfigFilePool::APP_ENV => $this->envConfig]);
        $this->storeManager->reinitStores();
    }

    /**
     * Test that initial scopes config are loaded if database is available
     *
     * @param array $websites
     * @param string $defaultWebsite
     * @param bool $offline
     * @throws LocalizedException
     * @dataProvider getDefaultDataProvider
     */
    public function testGetWebsites(array $websites, string $defaultWebsite, bool $offline = false): void
    {
        if ($offline) {
            // remove application environment config for emulate work without db
            $this->clearConfig(ConfigFilePool::APP_ENV);
        }
        $this->assertEquals($defaultWebsite, $this->storeManager->getWebsite()->getCode());
        $actualWebsites = array_keys($this->storeManager->getWebsites(true, true));
        $this->assertEmpty(array_diff($websites, $actualWebsites));
    }

    /**
     * @return array
     */
    public function getDefaultDataProvider(): array
    {
        return [
            [
                [
                    'admin',
                    'main',
                ],
                'main',
                true
            ],
            [
                [
                    'admin',
                    'base',
                ],
                'base',
                false
            ],
        ];
    }

    private function clearConfig(string $type): void
    {
        $this->filesystem
            ->getDirectoryWrite(DirectoryList::CONFIG)
            ->writeFile(
                $this->configFilePool->getPath($type),
                "<?" . "php\n return [];\n"
            );
        /** @var DeploymentConfig $config */
        $config = Bootstrap::getObjectManager()->get(DeploymentConfig::class);
        $config->resetData();
    }

    /**
     * @return void
     */
    private function loadDumpConfig(): void
    {
        $data = array_replace_recursive(
            $this->config,
            $this->getDumpConfig()
        );
        $this->writer->saveConfig([ConfigFilePool::APP_CONFIG => $data], true);
    }

    /**
     * @return array
     */
    private function getDumpConfig(): array
    {
        return require __DIR__ . '/../../../_files/dump_config.php';
    }

    /**
     * @return array
     */
    private function loadConfig(): array
    {
        return $this->reader->load(ConfigFilePool::APP_CONFIG);
    }

    /**
     * @return array
     */
    private function loadEnvConfig(): array
    {
        return $this->reader->load(ConfigFilePool::APP_ENV);
    }
}
