<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Backup model factory
 *
 * @method \Magento\Backup\Model\Backup create($timestamp, $type)
 */
namespace Magento\Backup\Model;

/**
 * @api
 * @since 100.0.2
 */
class BackupFactory
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager;

    /**
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     */
    public function __construct(\Magento\Framework\ObjectManagerInterface $objectManager)
    {
        $this->_objectManager = $objectManager;
    }

    /**
     * Load backup by it's type and creation timestamp
     *
     * @param int $timestamp
     * @param string $type
     * @return \Magento\Backup\Model\Backup
     */
    public function create($timestamp, $type)
    {
        $fsCollection = $this->_objectManager->create(\Magento\Backup\Model\Fs\Collection::class);
        $backupInstance = $this->_objectManager->create(\Magento\Backup\Model\Backup::class);

        foreach ($fsCollection as $backup) {
            if ($backup->getTime() === (int) $timestamp && $backup->getType() === $type) {
                $backupInstance->setData(['id' => $backup->getId()])
                    ->setType($backup->getType())
                    ->setTime($backup->getTime())
                    ->setName($backup->getName())
                    ->setPath($backup->getPath());
                break;
            }
        }

        return $backupInstance;
    }
}
