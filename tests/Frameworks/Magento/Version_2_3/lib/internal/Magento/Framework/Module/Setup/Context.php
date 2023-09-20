<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Module\Setup;

/**
 * Constructor modification point for Magento\Framework\Module\Setup.
 *
 * All context classes were introduced to allow for backwards compatible constructor modifications
 * of classes that were supposed to be extended by extension developers.
 *
 * Do not call methods of this class directly.
 *
 * As Magento moves from inheritance-based APIs all such classes will be deprecated together with
 * the classes they were introduced for.
 */
class Context implements \Magento\Framework\ObjectManager\ContextInterface
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $_eventManager;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $_resourceModel;

    /**
     * @var \Magento\Framework\Module\Dir\Reader
     */
    protected $_modulesReader;

    /**
     * @var \Magento\Framework\Module\ModuleListInterface
     */
    protected $_moduleList;

    /**
     * @var \Magento\Framework\Module\ResourceInterface
     */
    protected $_resource;

    /**
     * @var \Magento\Framework\Module\Setup\MigrationFactory
     */
    protected $_migrationFactory;

    /**
     * @var \Magento\Framework\Encryption\EncryptorInterface
     */
    protected $_encryptor;

    /**
     * @var \Magento\Framework\Filesystem
     */
    protected $filesystem;

    /**
     * Constructor
     *
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Framework\App\ResourceConnection $appResource
     * @param \Magento\Framework\Module\Dir\Reader $modulesReader
     * @param \Magento\Framework\Module\ModuleListInterface $moduleList
     * @param \Magento\Framework\Module\ResourceInterface $resource
     * @param \Magento\Framework\Module\Setup\MigrationFactory $migrationFactory
     * @param \Magento\Framework\Encryption\EncryptorInterface $encryptor
     * @param \Magento\Framework\Filesystem $filesystem
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\App\ResourceConnection $appResource,
        \Magento\Framework\Module\Dir\Reader $modulesReader,
        \Magento\Framework\Module\ModuleListInterface $moduleList,
        \Magento\Framework\Module\ResourceInterface $resource,
        \Magento\Framework\Module\Setup\MigrationFactory $migrationFactory,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Magento\Framework\Filesystem $filesystem
    ) {
        $this->_logger = $logger;
        $this->_eventManager = $eventManager;
        $this->_resourceModel = $appResource;
        $this->_modulesReader = $modulesReader;
        $this->_moduleList = $moduleList;
        $this->_resource = $resource;
        $this->_migrationFactory = $migrationFactory;
        $this->_encryptor = $encryptor;
        $this->filesystem = $filesystem;
    }

    /**
     * Retrieve event manager
     *
     * @return \Magento\Framework\Event\ManagerInterface
     */
    public function getEventManager()
    {
        return $this->_eventManager;
    }

    /**
     * Retrieve logger
     *
     * @return \Psr\Log\LoggerInterface
     */
    public function getLogger()
    {
        return $this->_logger;
    }

    /**
     * Retrieve module list
     *
     * @return \Magento\Framework\Module\ModuleListInterface
     */
    public function getModuleList()
    {
        return $this->_moduleList;
    }

    /**
     * Retrieve modules reader
     *
     * @return \Magento\Framework\Module\Dir\Reader
     */
    public function getModulesReader()
    {
        return $this->_modulesReader;
    }

    /**
     * Retrieve resource model
     *
     * @return \Magento\Framework\App\ResourceConnection
     */
    public function getResourceModel()
    {
        return $this->_resourceModel;
    }

    /**
     * Retrieve migration factory
     *
     * @return \Magento\Framework\Module\Setup\MigrationFactory
     */
    public function getMigrationFactory()
    {
        return $this->_migrationFactory;
    }

    /**
     * Retrieve resource
     *
     * @return \Magento\Framework\Module\ResourceInterface
     */
    public function getResource()
    {
        return $this->_resource;
    }

    /**
     * Retrieve encryptor
     *
     * @return \Magento\Framework\Encryption\EncryptorInterface
     */
    public function getEncryptor()
    {
        return $this->_encryptor;
    }

    /**
     * Retrieve filesystem
     *
     * @return \Magento\Framework\Filesystem
     */
    public function getFilesystem()
    {
        return $this->filesystem;
    }
}
