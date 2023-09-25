<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Setup\Test\Unit\Module;

use \Magento\Setup\Module\ResourceFactory;
use \Magento\Setup\Module\ConnectionFactory;

class ResourceFactoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ResourceFactory
     */
    private $resourceFactory;

    protected function setUp(): void
    {
        $serviceLocatorMock = $this->createMock(
            \Laminas\ServiceManager\ServiceLocatorInterface::class
        );
        $connectionFactory = new ConnectionFactory($serviceLocatorMock);
        $serviceLocatorMock
            ->expects($this->once())
            ->method('get')
            ->with(\Magento\Setup\Module\ConnectionFactory::class)
            ->willReturn($connectionFactory);
        $this->resourceFactory = new ResourceFactory($serviceLocatorMock);
    }

    public function testCreate()
    {
        $resource = $this->resourceFactory->create(
            $this->createMock(\Magento\Framework\App\DeploymentConfig::class)
        );
        $this->assertInstanceOf(\Magento\Framework\App\ResourceConnection::class, $resource);
    }
}
