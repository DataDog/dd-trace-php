<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\App\Test\Unit\Config;

class InitialTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    private $objectManager;

    /**
     * @var \Magento\Framework\App\Config\Initial
     */
    private $config;

    /**
     * @var \Magento\Framework\App\Cache\Type\Config|\PHPUnit\Framework\MockObject\MockObject
     */
    private $cacheMock;

    /**
     * @var array
     */
    private $data = [
        'data' => [
            'default' => ['key' => 'default_value'],
            'stores' => ['default' => ['key' => 'store_value']],
            'websites' => ['default' => ['key' => 'website_value']],
        ],
        'metadata' => ['metadata'],
    ];

    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->cacheMock = $this->createMock(\Magento\Framework\App\Cache\Type\Config::class);
        $this->cacheMock->expects($this->any())
            ->method('load')
            ->with('initial_config')
            ->willReturn(json_encode($this->data));
        $serializerMock = $this->createMock(\Magento\Framework\Serialize\SerializerInterface::class);
        $serializerMock->method('unserialize')
            ->willReturn($this->data);

        $this->config = $this->objectManager->getObject(
            \Magento\Framework\App\Config\Initial::class,
            [
                'cache' => $this->cacheMock,
                'serializer' => $serializerMock,
            ]
        );
    }

    /**
     * @param string $scope
     * @param array $expected
     * @dataProvider getDataDataProvider
     */
    public function testGetData($scope, $expected)
    {
        $this->assertEquals($expected, $this->config->getData($scope));
    }

    /**
     * @return array
     */
    public function getDataDataProvider()
    {
        return [
            ['default', ['key' => 'default_value']],
            ['stores|default', ['key' => 'store_value']],
            ['websites|default', ['key' => 'website_value']]
        ];
    }

    public function testGetMetadata()
    {
        $this->assertEquals(['metadata'], $this->config->getMetadata());
    }
}
