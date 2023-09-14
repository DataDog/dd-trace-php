<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Store\Test\Unit\Model\Config\Reader\Source\Dynamic;

use Magento\Framework\App\Config\Scope\Converter;
use Magento\Framework\DataObject;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Config\Reader\Source\Dynamic\Store as StoreSource;
use Magento\Store\Model\Config\Reader\Source\Dynamic\Website as WebsiteSource;
use Magento\Store\Model\ResourceModel\Config\Collection\ScopedFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Magento\Store\Model\WebsiteFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class StoreTest extends TestCase
{
    /**
     * @var ScopedFactory|MockObject
     */
    private $collectionFactory;

    /**
     * @var Converter|MockObject
     */
    private $converter;

    /**
     * @var WebsiteFactory|MockObject
     */
    private $websiteFactory;

    /**
     * @var Website|MockObject
     */
    private $website;

    /**
     * @var WebsiteSource|MockObject
     */
    private $websiteSource;

    /**
     * @var StoreManagerInterface|MockObject
     */
    private $storeManager;

    /**
     * @var StoreInterface|MockObject
     */
    private $store;

    /**
     * @var StoreSource
     */
    private $storeSource;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->collectionFactory = $this->getMockBuilder(ScopedFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMockForAbstractClass();
        $this->converter = $this->getMockBuilder(Converter::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->websiteFactory = $this->getMockBuilder(WebsiteFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMockForAbstractClass();
        $this->website = $this->getMockBuilder(\Magento\Store\Model\Website::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->websiteSource = $this->getMockBuilder(WebsiteSource::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->storeManager = $this->getMockBuilder(StoreManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->store = $this->getMockBuilder(StoreInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->storeSource = new StoreSource(
            $this->collectionFactory,
            $this->converter,
            $this->websiteFactory,
            $this->websiteSource,
            $this->storeManager
        );
    }

    /**
     * @return void
     */
    public function testGet(): void
    {
        $scopeCode = 'myStore';
        $expectedResult = [
            'config/key1' => 'default_db_value1',
            'config/key3' => 'default_db_value3',
        ];
        $this->storeManager->expects($this->once())
            ->method('getStore')
            ->with($scopeCode)
            ->willReturn($this->store);
        $this->store->expects($this->once())
            ->method('getId')
            ->willReturn(1);
        $this->store->expects($this->once())
            ->method('getWebsiteId')
            ->willReturn(1);
        $this->collectionFactory->expects($this->once())
            ->method('create')
            ->with(['scope' => ScopeInterface::SCOPE_STORES, 'scopeId' => 1])
            ->willReturn([
                new DataObject(['path' => 'config/key1', 'value' => 'default_db_value1']),
                new DataObject(['path' => 'config/key3', 'value' => 'default_db_value3']),
            ]);
        $this->websiteSource->expects($this->once())
            ->method('get')
            ->with(1)
            ->willReturn([]);

        $this->converter
            ->method('convert')
            ->withConsecutive([$expectedResult], [$expectedResult])
            ->willReturnArgument(0);

        $this->assertEquals($expectedResult, $this->storeSource->get($scopeCode));
    }
}
