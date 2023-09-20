<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\PageCache\Test\Unit\Observer;

class FlushAllCacheTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\PageCache\Observer\FlushAllCache */
    private $_model;

    /** @var \PHPUnit\Framework\MockObject\MockObject|\Magento\PageCache\Model\Config */
    private $_configMock;

    /** @var  \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\App\PageCache\Cache */
    private $_cacheMock;

    /** @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\Event\Observer */
    private $observerMock;

    /** @var  \PHPUnit\Framework\MockObject\MockObject|\Magento\PageCache\Model\Cache\Type */
    private $fullPageCacheMock;

    /**
     * Set up all mocks and data for test
     */
    protected function setUp(): void
    {
        $this->_configMock = $this->createPartialMock(\Magento\PageCache\Model\Config::class, ['getType', 'isEnabled']);
        $this->_cacheMock = $this->createPartialMock(\Magento\Framework\App\PageCache\Cache::class, ['clean']);
        $this->fullPageCacheMock = $this->createPartialMock(\Magento\PageCache\Model\Cache\Type::class, ['clean']);
        $this->observerMock = $this->createMock(\Magento\Framework\Event\Observer::class);

        $this->_model = new \Magento\PageCache\Observer\FlushAllCache(
            $this->_configMock,
            $this->_cacheMock
        );

        $reflection = new \ReflectionClass(\Magento\PageCache\Observer\FlushAllCache::class);
        $reflectionProperty = $reflection->getProperty('fullPageCache');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->_model, $this->fullPageCacheMock);
    }

    /**
     * Test case for flushing all the cache
     */
    public function testExecute()
    {
        $this->_configMock->expects(
            $this->once()
        )->method(
            'getType'
        )->willReturn(
            \Magento\PageCache\Model\Config::BUILT_IN
        );

        $this->fullPageCacheMock->expects($this->once())->method('clean');
        $this->_model->execute($this->observerMock);
    }
}
