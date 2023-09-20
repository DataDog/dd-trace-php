<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Cms\Test\Unit\Block;

/**
 * Class PageTest
 */
class PageTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Cms\Block\Page
     */
    protected $block;

    /**
     * @var \Magento\Cms\Model\Page
     */
    protected $page;

    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->block = $objectManager->getObject(\Magento\Cms\Block\Page::class);
        $this->page = $objectManager->getObject(\Magento\Cms\Model\Page::class);
        $reflection = new \ReflectionClass($this->page);
        $reflectionProperty = $reflection->getProperty('_idFieldName');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->page, 'page_id');
        $this->page->setId(1);
    }

    protected function tearDown(): void
    {
        $this->block = null;
    }

    public function testGetIdentities()
    {
        $id = 1;
        $this->block->setPage($this->page);
        $this->assertEquals(
            [\Magento\Cms\Model\Page::CACHE_TAG . '_' . $id],
            $this->block->getIdentities()
        );
    }
}
