<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\AwsS3\Driver;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\RemoteStorage\Driver\Adapter\Cache\CacheInterfaceFactory;
use Magento\RemoteStorage\Driver\Adapter\CachedAdapterInterfaceFactory;
use Magento\RemoteStorage\Driver\Adapter\MetadataProviderInterfaceFactory;
use Magento\RemoteStorage\Driver\DriverException;
use Magento\RemoteStorage\Driver\DriverFactoryInterface;
use Magento\RemoteStorage\Driver\RemoteDriverInterface;
use Magento\RemoteStorage\Model\Config;

/**
 * Creates a pre-configured instance of AWS S3 driver.
 */
class AwsS3Factory implements DriverFactoryInterface
{
    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var MetadataProviderInterfaceFactory
     */
    private $metadataProviderFactory;

    /**
     * @var CacheInterfaceFactory
     */
    private $cacheInterfaceFactory;

    /**
     * @var CachedAdapterInterfaceFactory
     */
    private $cachedAdapterInterfaceFactory;

    /**
     * @var string|null
     */
    private $cachePrefix;

    /**
     * @param ObjectManagerInterface $objectManager
     * @param Config $config
     * @param MetadataProviderInterfaceFactory $metadataProviderFactory
     * @param CacheInterfaceFactory $cacheInterfaceFactory
     * @param CachedAdapterInterfaceFactory $cachedAdapterInterfaceFactory
     * @param string|null $cachePrefix
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        Config $config,
        MetadataProviderInterfaceFactory $metadataProviderFactory,
        CacheInterfaceFactory $cacheInterfaceFactory,
        CachedAdapterInterfaceFactory $cachedAdapterInterfaceFactory,
        string $cachePrefix = null
    ) {
        $this->objectManager = $objectManager;
        $this->config = $config;
        $this->metadataProviderFactory = $metadataProviderFactory;
        $this->cacheInterfaceFactory = $cacheInterfaceFactory;
        $this->cachedAdapterInterfaceFactory = $cachedAdapterInterfaceFactory;
        $this->cachePrefix = $cachePrefix;
    }

    /**
     * @inheritDoc
     */
    public function create(): RemoteDriverInterface
    {
        try {
            return $this->createConfigured(
                $this->config->getConfig(),
                $this->config->getPrefix()
            );
        } catch (LocalizedException $exception) {
            throw new DriverException(__($exception->getMessage()), $exception);
        }
    }

    /**
     * @inheritDoc
     */
    public function createConfigured(
        array $config,
        string $prefix,
        string $cacheAdapter = '',
        array $cacheConfig = []
    ): RemoteDriverInterface {
        $config['version'] = 'latest';

        if (empty($config['credentials']['key']) || empty($config['credentials']['secret'])) {
            unset($config['credentials']);
        }

        if (empty($config['bucket']) || empty($config['region'])) {
            throw new DriverException(__('Bucket and region are required values'));
        }

        if (!empty($config['http_handler'])) {
            $config['http_handler'] = $this->objectManager->create($config['http_handler'])($config);
        }

        if (!empty($config['path_style'])) {
            $config['use_path_style_endpoint'] = boolval($config['path_style']);
        }

        $client = new S3Client($config);
        $adapter = new AwsS3V3Adapter($client, $config['bucket'], $prefix);
        $cache = $this->cacheInterfaceFactory->create(
            // Custom cache prefix required to distinguish cache records for different sources.
            // phpcs:ignore Magento2.Security.InsecureFunction
            $this->cachePrefix ? ['prefix' => $this->cachePrefix] : ['prefix' => md5($config['bucket'] . $prefix)]
        );
        $metadataProvider = $this->metadataProviderFactory->create(
            [
                'adapter' => $adapter,
                'cache' => $cache
            ]
        );
        $objectUrl = rtrim($client->getObjectUrl($config['bucket'], './'), '/') . trim($prefix, '\\/') . '/';
        return $this->objectManager->create(
            AwsS3::class,
            [
                'adapter' => $this->cachedAdapterInterfaceFactory->create(
                    [
                        'adapter' => $adapter,
                        'cache' => $cache,
                        'metadataProvider' => $metadataProvider
                    ]
                ),
                'objectUrl' => $objectUrl,
                'metadataProvider' => $metadataProvider,
            ]
        );
    }
}
