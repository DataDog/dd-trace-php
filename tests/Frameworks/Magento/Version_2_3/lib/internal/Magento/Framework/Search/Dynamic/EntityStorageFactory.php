<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Search\Dynamic;

use Magento\Framework\ObjectManagerInterface;

/**
 * EntityStorage Factory
 * @api
 * @since 100.0.2
 */
class EntityStorageFactory
{
    /**
     * Object Manager instance
     *
     * @var ObjectManagerInterface
     */
    protected $objectManager = null;

    /**
     * Instance name to create
     *
     * @var string
     */
    protected $instanceName = null;

    /**
     * Factory constructor
     *
     * @param ObjectManagerInterface $objectManager
     * @param string $instanceName
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        $instanceName = \Magento\Framework\Search\Dynamic\EntityStorage::class
    ) {
        $this->objectManager = $objectManager;
        $this->instanceName = $instanceName;
    }

    /**
     * Create class instance with specified parameters
     *
     * @param array $source
     * @return \Magento\Framework\Search\Dynamic\EntityStorage
     */
    public function create($source)
    {
        return $this->objectManager->create($this->instanceName, ['source' => $source]);
    }
}
