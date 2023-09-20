<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Setup\Test\Unit\Model;

use \Magento\Setup\Model\AdminAccountFactory;

class AdminAccountFactoryTest extends \PHPUnit\Framework\TestCase
{
    public function testCreate()
    {
        $serviceLocatorMock =
            $this->createMock(\Laminas\ServiceManager\ServiceLocatorInterface::class);
        $serviceLocatorMock
            ->expects($this->once())
            ->method('get')
            ->with(\Magento\Framework\Encryption\Encryptor::class)
            ->willReturn($this->getMockForAbstractClass(\Magento\Framework\Encryption\EncryptorInterface::class));
        $adminAccountFactory = new AdminAccountFactory($serviceLocatorMock);
        $adminAccount = $adminAccountFactory->create(
            $this->getMockForAbstractClass(\Magento\Framework\DB\Adapter\AdapterInterface::class),
            []
        );
        $this->assertInstanceOf(\Magento\Setup\Model\AdminAccount::class, $adminAccount);
    }
}
