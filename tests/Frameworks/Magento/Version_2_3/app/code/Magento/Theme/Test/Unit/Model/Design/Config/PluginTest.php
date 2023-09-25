<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Theme\Test\Unit\Model\Design\Config;

use Magento\Theme\Model\Design\Config\Plugin;

class PluginTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Framework\Event\ManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $eventManager;

    /** @var \Magento\Store\Model\StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $storeManager;

    /** @var \Magento\Theme\Model\DesignConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    protected $repository;

    /** @var \Magento\Theme\Api\Data\DesignConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $designConfig;

    /** @var \Magento\Store\Api\Data\WebsiteInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $website;

    /** @var \Magento\Store\Api\Data\StoreInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $store;

    /** @var  Plugin */
    protected $plugin;

    protected function setUp(): void
    {
        $this->eventManager = $this->getMockForAbstractClass(
            \Magento\Framework\Event\ManagerInterface::class,
            [],
            '',
            false
        );
        $this->storeManager = $this->getMockForAbstractClass(
            \Magento\Store\Model\StoreManagerInterface::class,
            [],
            '',
            false
        );
        $this->repository = $this->createMock(\Magento\Theme\Model\DesignConfigRepository::class);
        $this->designConfig = $this->getMockForAbstractClass(
            \Magento\Theme\Api\Data\DesignConfigInterface::class,
            [],
            '',
            false
        );
        $this->website = $this->getMockForAbstractClass(
            \Magento\Store\Api\Data\WebsiteInterface::class,
            [],
            '',
            false
        );
        $this->store = $this->getMockForAbstractClass(
            \Magento\Store\Api\Data\StoreInterface::class,
            [],
            '',
            false
        );
        $this->plugin = new Plugin($this->eventManager, $this->storeManager);
    }

    public function testAfterSave()
    {
        $this->designConfig->expects($this->exactly(2))
            ->method('getScope')
            ->willReturn('website');
        $this->designConfig->expects($this->once())
            ->method('getScopeId')
            ->willReturn(1);
        $this->storeManager->expects($this->once())
            ->method('getWebsite')
            ->with(1)
            ->willReturn($this->website);

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with(
                'admin_system_config_changed_section_design',
                ['website' => $this->website, 'store' => '']
            );
        $this->plugin->afterSave($this->repository, $this->designConfig);
    }

    public function testAfterSaveDispatchWithStore()
    {
        $this->designConfig->expects($this->exactly(2))
            ->method('getScope')
            ->willReturn('store');
        $this->designConfig->expects($this->once())
            ->method('getScopeId')
            ->willReturn(1);
        $this->storeManager->expects($this->once())
            ->method('getStore')
            ->with(1)
            ->willReturn($this->store);

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with(
                'admin_system_config_changed_section_design',
                ['website' => '', 'store' => $this->store]
            );
        $this->plugin->afterSave($this->repository, $this->designConfig);
    }
}
