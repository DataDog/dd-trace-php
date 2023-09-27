<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Integration\Model;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Integration\Model\Cache\TypeIntegration;
use Magento\Integration\Model\Config\Integration\Reader;

/**
 * Integration Api Config Model.
 *
 * This is a parent class for storing information about Integrations.
 * @deprecated 100.1.0
 */
class IntegrationConfig
{
    const CACHE_ID = 'integration-api';

    /**
     * @var \Magento\Framework\App\Cache\Type\Config
     */
    protected $_configCacheType;

    /**
     * @var \Magento\Integration\Model\Config\Integration\Reader
     */
    protected $_configReader;

    /**
     * Array of integrations with resource permissions from api config
     *
     * @var array
     */
    protected $_integrations;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @param TypeIntegration $configCacheType
     * @param Reader $configReader
     * @param SerializerInterface $serializer
     */
    public function __construct(
        TypeIntegration $configCacheType,
        Reader $configReader,
        SerializerInterface $serializer = null
    ) {
        $this->_configCacheType = $configCacheType;
        $this->_configReader = $configReader;
        $this->serializer = $serializer ?: ObjectManager::getInstance()->get(SerializerInterface::class);
    }

    /**
     * Return integrations loaded from cache if enabled or from files merged previously
     *
     * @return array
     * @api
     */
    public function getIntegrations()
    {
        if (null === $this->_integrations) {
            $integrations = $this->_configCacheType->load(self::CACHE_ID);
            if ($integrations && is_string($integrations)) {
                $this->_integrations = $this->serializer->unserialize($integrations);
            } else {
                $this->_integrations = $this->_configReader->read();
                $this->_configCacheType->save(
                    $this->serializer->serialize($this->_integrations),
                    self::CACHE_ID,
                    [TypeIntegration::CACHE_TAG]
                );
            }
        }
        return $this->_integrations;
    }
}
