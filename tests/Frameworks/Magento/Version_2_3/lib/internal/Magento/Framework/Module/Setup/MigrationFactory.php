<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Module\Setup;

/**
 * Factory class for \Magento\Framework\Module\Setup\Migration
 */
class MigrationFactory
{
    /**
     * Object Manager instance
     *
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager = null;

    /**
     * Instance name to create
     *
     * @var string
     */
    protected $_instanceName = null;

    /**
     * Factory constructor
     *
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param string $instanceName
     */
    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        $instanceName = \Magento\Framework\Module\Setup\Migration::class
    ) {
        $this->_objectManager = $objectManager;
        $this->_instanceName = $instanceName;
    }

    /**
     * Create class instance with specified parameters
     *
     * @param array $data
     * @return \Magento\Framework\Module\Setup\Migration
     * @throws \InvalidArgumentException
     */
    public function create(array $data = [])
    {
        $migrationInstance = $this->_objectManager->create($this->_instanceName, $data);

        if (!$migrationInstance instanceof \Magento\Framework\Module\Setup\Migration) {
            throw new \InvalidArgumentException(
                $this->_instanceName . ' doesn\'n extend \Magento\Framework\Module\Setup\Migration'
            );
        }
        return $migrationInstance;
    }
}
