<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Filesystem\File;

use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Filesystem\DriverPool;

/**
 * Opens a file for reading
 * @api
 * @since 100.0.2
 */
class ReadFactory
{
    /**
     * Pool of filesystem drivers
     *
     * @var DriverPool
     */
    private $driverPool;

    /**
     * Constructor
     *
     * @param DriverPool $driverPool
     */
    public function __construct(DriverPool $driverPool)
    {
        $this->driverPool = $driverPool;
    }

    /**
     * Create a {@see ReaderInterface}
     *
     * @param string $path
     * @param DriverInterface|string $driver Driver or driver code
     * @return \Magento\Framework\Filesystem\File\ReadInterface
     */
    public function create($path, $driver)
    {
        if (is_string($driver)) {
            return new Read($path, $this->driverPool->getDriver($driver));
        }
        return new Read($path, $driver);
    }
}
