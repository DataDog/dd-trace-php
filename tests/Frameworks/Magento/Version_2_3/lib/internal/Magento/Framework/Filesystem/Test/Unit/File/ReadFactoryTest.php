<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Filesystem\Test\Unit\File;

use \Magento\Framework\Filesystem\File\ReadFactory;

/**
 * Class ReadFactoryTest
 */
class ReadFactoryTest extends \PHPUnit\Framework\TestCase
{
    public function testCreate()
    {
        $driverPool = $this->createPartialMock(\Magento\Framework\Filesystem\DriverPool::class, ['getDriver']);
        $driverPool->expects($this->never())->method('getDriver');
        $driver = $this->getMockForAbstractClass(\Magento\Framework\Filesystem\DriverInterface::class);
        $driver->expects($this->any())->method('isExists')->willReturn(true);
        $factory = new ReadFactory($driverPool);
        $result = $factory->create('path', $driver);
        $this->assertInstanceOf(\Magento\Framework\Filesystem\File\Read::class, $result);
    }

    public function testCreateWithDriverCode()
    {
        $driverPool = $this->createPartialMock(\Magento\Framework\Filesystem\DriverPool::class, ['getDriver']);
        $driverMock = $this->getMockForAbstractClass(\Magento\Framework\Filesystem\DriverInterface::class);
        $driverMock->expects($this->any())->method('isExists')->willReturn(true);
        $driverPool->expects($this->once())->method('getDriver')->willReturn($driverMock);
        $factory = new ReadFactory($driverPool);
        $result = $factory->create('path', 'driverCode');
        $this->assertInstanceOf(\Magento\Framework\Filesystem\File\Read::class, $result);
    }
}
