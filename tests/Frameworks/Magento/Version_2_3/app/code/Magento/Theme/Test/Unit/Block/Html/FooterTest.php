<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Theme\Test\Unit\Block\Html;

class FooterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Theme\Block\Html\Footer
     */
    protected $block;

    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->block = $objectManager->getObject(\Magento\Theme\Block\Html\Footer::class);
    }

    protected function tearDown(): void
    {
        $this->block = null;
    }

    public function testGetIdentities()
    {
        $this->assertEquals(
            [\Magento\Store\Model\Store::CACHE_TAG, \Magento\Cms\Model\Block::CACHE_TAG],
            $this->block->getIdentities()
        );
    }

    /**
     * Check Footer block has cache lifetime.
     *
     * @throws \ReflectionException
     * @return void
     */
    public function testGetCacheLifetime()
    {
        $reflection = new \ReflectionClass($this->block);
        $method = $reflection->getMethod('getCacheLifetime');
        $method->setAccessible(true);
        $this->assertEquals(3600, $method->invoke($this->block));
    }
}
