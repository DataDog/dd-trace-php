<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Theme\Test\Unit\Model\Data\Design;

use Magento\Framework\App\ScopeValidatorInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Theme\Api\Data\DesignConfigDataInterface;
use Magento\Theme\Api\Data\DesignConfigInterface;
use Magento\Theme\Model\Data\Design\ConfigFactory;
use Magento\Theme\Model\Design\Config\MetadataProviderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigFactoryTest extends TestCase
{
    /** @var \Magento\Theme\Api\Data\DesignConfigInterfaceFactory|MockObject */
    protected $designConfigFactory;

    /** @var MetadataProviderInterface|MockObject */
    protected $metadataProvider;

    /** @var \Magento\Theme\Api\Data\DesignConfigDataInterfaceFactory|MockObject */
    protected $designConfigDataFactory;

    /** @var \Magento\Theme\Api\Data\DesignConfigExtensionFactory|MockObject */
    protected $configExtensionFactory;

    /** @var DesignConfigInterface|MockObject */
    protected $designConfig;

    /** @var DesignConfigDataInterface|MockObject */
    protected $designConfigData;

    /** @var \Magento\Theme\Api\Data\DesignConfigExtension|MockObject */
    protected $designConfigExtension;

    /** @var ScopeValidatorInterface|MockObject */
    protected $scopeValidator;

    /** @var ConfigFactory */
    protected $factory;

    /** @var StoreManagerInterface|MockObject */
    protected $storeManager;

    /** @var WebsiteInterface|MockObject */
    protected $website;

    protected function setUp(): void
    {
        $this->designConfigFactory = $this->createPartialMock(
            \Magento\Theme\Api\Data\DesignConfigInterfaceFactory::class,
            ['create']
        );
        $this->metadataProvider = $this->getMockForAbstractClass(
            MetadataProviderInterface::class,
            [],
            '',
            false
        );
        $this->designConfigDataFactory = $this->createPartialMock(
            \Magento\Theme\Api\Data\DesignConfigDataInterfaceFactory::class,
            ['create']
        );
        $this->configExtensionFactory = $this->createPartialMock(
            \Magento\Theme\Api\Data\DesignConfigExtensionFactory::class,
            ['create']
        );
        $this->designConfig = $this->getMockForAbstractClass(
            DesignConfigInterface::class,
            [],
            '',
            false
        );
        $this->designConfigData = $this->getMockForAbstractClass(
            DesignConfigDataInterface::class,
            [],
            '',
            false
        );
        $this->designConfigExtension = $this->getMockForAbstractClass(
            \Magento\Theme\Api\Data\DesignConfigExtension::class,
            [],
            '',
            false,
            false,
            true,
            ['setDesignConfigData']
        );
        $this->scopeValidator = $this->getMockBuilder(ScopeValidatorInterface::class)
            ->getMockForAbstractClass();
        $this->storeManager = $this->getMockBuilder(StoreManagerInterface::class)
            ->getMockForAbstractClass();
        $this->website = $this->getMockBuilder(WebsiteInterface::class)
            ->getMockForAbstractClass();

        $this->factory = new ConfigFactory(
            $this->designConfigFactory,
            $this->metadataProvider,
            $this->designConfigDataFactory,
            $this->configExtensionFactory,
            $this->scopeValidator,
            $this->storeManager
        );
    }

    public function testCreate()
    {
        $scope = 'default';
        $scopeId = 0;
        $data = [
            'header_default_title' => 'value'
        ];
        $metadata = [
            'header_default_title' => [
                'path' => 'design/header/default_title',
                'fieldset' => 'head'
            ],
            'head_default_description' => [
                'path' => 'design/head/default_description',
                'fieldset' => 'head'
            ],
        ];

        $this->scopeValidator->expects($this->once())
            ->method('isValidScope')
            ->with($scope, $scopeId)
            ->willReturn(true);
        $this->storeManager->expects($this->once())
            ->method('isSingleStoreMode')
            ->willReturn(false);

        $this->designConfigFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->designConfig);
        $this->designConfig->expects($this->once())
            ->method('setScope')
            ->willReturn('default');
        $this->designConfig->expects($this->once())
            ->method('setScopeId')
            ->willReturn(0);
        $this->metadataProvider->expects($this->once())
            ->method('get')
            ->willReturn($metadata);
        $this->designConfigDataFactory->expects($this->exactly(2))
            ->method('create')
            ->willReturn($this->designConfigData);
        $this->designConfigData->expects($this->exactly(2))
            ->method('setPath')
            ->withConsecutive(
                ['design/header/default_title'],
                ['design/head/default_description']
            );
        $this->designConfigData->expects($this->exactly(2))
            ->method('setFieldConfig')
            ->withConsecutive(
                [
                    [
                        'path' => 'design/header/default_title',
                        'fieldset' => 'head',
                        'field' => 'header_default_title'
                    ]
                ],
                [
                    [
                        'path' => 'design/head/default_description',
                        'fieldset' => 'head',
                        'field' => 'head_default_description'
                    ]
                ]
            );
        $this->designConfigData->expects($this->once())
            ->method('setValue')
            ->with('value');
        $this->configExtensionFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->designConfigExtension);
        $this->designConfigExtension->expects($this->once())
            ->method('setDesignConfigData')
            ->with([$this->designConfigData, $this->designConfigData]);
        $this->designConfig->expects($this->once())
            ->method('setExtensionAttributes')
            ->with($this->designConfigExtension);
        $this->assertSame($this->designConfig, $this->factory->create($scope, $scopeId, $data));
    }

    public function testCreateInSingleStoreMode()
    {
        $scope = 'default';
        $scopeId = 0;
        $data = [
            'header_default_title' => 'value'
        ];
        $metadata = [
            'header_default_title' => [
                'path' => 'design/header/default_title',
                'fieldset' => 'head'
            ],
            'head_default_description' => [
                'path' => 'design/head/default_description',
                'fieldset' => 'head'
            ],
        ];

        $this->scopeValidator->expects($this->once())
            ->method('isValidScope')
            ->with($scope, $scopeId)
            ->willReturn(true);
        $this->storeManager->expects($this->once())
            ->method('isSingleStoreMode')
            ->willReturn(true);
        $this->storeManager->expects($this->once())
            ->method('getWebsites')
            ->willReturn([$this->website]);
        $this->website->expects($this->once())
            ->method('getId')
            ->willReturn(1);

        $this->designConfigFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->designConfig);
        $this->designConfig->expects($this->once())
            ->method('setScope')
            ->willReturn('websites');
        $this->designConfig->expects($this->once())
            ->method('setScopeId')
            ->willReturn(1);
        $this->metadataProvider->expects($this->once())
            ->method('get')
            ->willReturn($metadata);
        $this->designConfigDataFactory->expects($this->exactly(2))
            ->method('create')
            ->willReturn($this->designConfigData);
        $this->designConfigData->expects($this->exactly(2))
            ->method('setPath')
            ->withConsecutive(
                ['design/header/default_title'],
                ['design/head/default_description']
            );
        $this->designConfigData->expects($this->exactly(2))
            ->method('setFieldConfig')
            ->withConsecutive(
                [
                    [
                        'path' => 'design/header/default_title',
                        'fieldset' => 'head',
                        'field' => 'header_default_title'
                    ]
                ],
                [
                    [
                        'path' => 'design/head/default_description',
                        'fieldset' => 'head',
                        'field' => 'head_default_description'
                    ]
                ]
            );
        $this->designConfigData->expects($this->once())
            ->method('setValue')
            ->with('value');
        $this->configExtensionFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->designConfigExtension);
        $this->designConfigExtension->expects($this->once())
            ->method('setDesignConfigData')
            ->with([$this->designConfigData, $this->designConfigData]);
        $this->designConfig->expects($this->once())
            ->method('setExtensionAttributes')
            ->with($this->designConfigExtension);
        $this->assertSame($this->designConfig, $this->factory->create($scope, $scopeId, $data));
    }
}
