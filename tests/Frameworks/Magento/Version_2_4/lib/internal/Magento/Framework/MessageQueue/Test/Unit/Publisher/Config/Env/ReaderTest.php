<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\MessageQueue\Test\Unit\Publisher\Config\Env;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\CacheInterface;
use Magento\Framework\MessageQueue\Config\CompositeReader;
use Magento\Framework\MessageQueue\Config\Data;
use Magento\Framework\MessageQueue\Publisher\Config\Env\Reader;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReaderTest extends TestCase
{
    /**
     * @var MockObject
     */
    private $deploymentConfigMock;

    /**
     * @var MockObject
     */
    private $configDataMock;

    /**
     * @var Reader
     *
     */
    private $reader;

    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);
        $this->deploymentConfigMock = $this->getMockBuilder(DeploymentConfig::class)
            ->disableOriginalConstructor()
            ->getMock();
        $cache = $this->getMockBuilder(CacheInterface::class)
            ->getMock();
        $data = [
            'topics' => [
                'inventory.counter.updated' => [
                    'disabled' => false,
                    'publisher' => 'amqp-magento'
                ],
            ],
            'publishers' => [
                'amqp-magento' => [
                    'name' => 'amqp-magento',
                    'connection' => 'db',
                    'exchange' => 'magento-db'
                ],
            ]

        ];
        $reader = $this->getMockBuilder(CompositeReader::class)
            ->disableOriginalConstructor()
            ->getMock();
        $reader->expects($this->any())->method('read')->willReturn($data);
        $cache->expects($this->any())->method('load')->willReturn(false);
        $this->configDataMock = $objectManager->getObject(
            Data::class,
            [
                'cache' => $cache,
                'reader' => $reader
            ]
        );

        $this->reader = new Reader(
            $this->deploymentConfigMock,
            $this->configDataMock,
            [
                'amqp-magento' => 'amqp',
                'db-magento-db' => 'db'
            ]
        );
    }

    public function testReadCurrentConfig()
    {
        $configData = include __DIR__ . '/../../../_files/env_2_2.php';
        $this->deploymentConfigMock->expects($this->once())->method('getConfigData')
            ->with('queue')->willReturn($configData);
        $actualResult = $this->reader->read();
        $this->assertEquals($configData['config']['publishers'], $actualResult);
    }

    public function testReadPreviousConfig()
    {
        $configData = include __DIR__ . '/../../../_files/env_2_1.php';
        $this->deploymentConfigMock->expects($this->once())->method('getConfigData')
            ->with('queue')->willReturn($configData);
        $actualResult = $this->reader->read();
        $expectedResult = [
            'inventory.counter.updated' => [
                'connections' => [
                    'amqp' => [
                        'name' => 'db',
                        'exchange' => 'magento-db',
                        'disabled' => false
                    ]
                ],
                'disabled' => false
            ]
        ];
        $this->assertEquals($expectedResult, $actualResult);
    }
}
