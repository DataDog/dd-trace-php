<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Store\Test\Unit\Model;

use Magento\Framework\App\Config;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\ResourceModel\Store\CollectionFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\StoreRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class StoreRepositoryTest extends TestCase
{
    /**
     * @var StoreFactory|MockObject
     */
    protected $storeFactory;

    /**
     * @var CollectionFactory|MockObject
     */
    protected $storeCollectionFactory;

    /**
     * @var bool
     */
    protected $allLoaded = false;

    /**
     * @var StoreRepositoryInterface
     */
    private $storeRepository;

    /**
     * @var Config|MockObject
     */
    private $appConfigMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->storeFactory = $this->getMockBuilder(StoreFactory::class)
            ->onlyMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->storeCollectionFactory = $this->getMockBuilder(CollectionFactory::class)
            ->onlyMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->storeRepository = new StoreRepository(
            $this->storeFactory,
            $this->storeCollectionFactory
        );
        $this->appConfigMock = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->initDistroList();
    }

    /**
     * @return void
     */
    private function initDistroList(): void
    {
        $repositoryReflection = new \ReflectionClass($this->storeRepository);
        $deploymentProperty = $repositoryReflection->getProperty('appConfig');
        $deploymentProperty->setAccessible(true);
        $deploymentProperty->setValue($this->storeRepository, $this->appConfigMock);
    }

    /**
     * @return void
     */
    public function testGetWithException(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('The store that was requested wasn\'t found. Verify the store and try again.');
        $storeMock = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->storeFactory->expects($this->once())
            ->method('create')
            ->willReturn($storeMock);

        $this->storeRepository->get('some_code');
    }

    /**
     * @return void
     */
    public function testGetWithAvailableStoreFromScope(): void
    {
        $storeMock = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storeMock->expects($this->exactly(2))
            ->method('getId')
            ->willReturn(1);
        $this->storeFactory->expects($this->once())
            ->method('create')
            ->willReturn($storeMock);

        $this->assertEquals($storeMock, $this->storeRepository->get('some_code'));
    }

    /**
     * @return void
     */
    public function testGetByIdWithAvailableStoreFromScope(): void
    {
        $storeMock = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storeMock->expects($this->once())
            ->method('getId')
            ->willReturn(1);
        $storeMock->expects($this->once())
            ->method('getCode')
            ->willReturn('some_code');
        $this->storeFactory->expects($this->once())
            ->method('create')
            ->willReturn($storeMock);
        $this->appConfigMock->expects($this->once())
            ->method('get')
            ->willReturn([]);

        $this->assertEquals($storeMock, $this->storeRepository->getById(1));
    }

    /**
     * @return void
     */
    public function testGetByIdWithException(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('The store that was requested wasn\'t found. Verify the store and try again.');
        $storeMock = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->storeFactory->expects($this->once())
            ->method('create')
            ->willReturn($storeMock);
        $this->appConfigMock->expects($this->once())
            ->method('get')
            ->willReturn([]);
        $this->storeRepository->getById(1);
    }

    /**
     * @return void
     */
    public function testGetList(): void
    {
        $storeMock1 = $this->getMockForAbstractClass(StoreInterface::class);
        $storeMock1->expects($this->once())
            ->method('getCode')
            ->willReturn('some_code');
        $storeMock1->expects($this->once())
            ->method('getId')
            ->willReturn(1);
        $storeMock2 = $this->getMockForAbstractClass(StoreInterface::class);
        $storeMock2->expects($this->once())
            ->method('getCode')
            ->willReturn('some_code_2');
        $storeMock2->expects($this->once())
            ->method('getId')
            ->willReturn(2);
        $this->appConfigMock->expects($this->once())
            ->method('get')
            ->willReturn([
                [
                    'code' => 'some_code'
                ],
                [
                    'code' => 'some_code_2'
                ]
            ]);
        $this->storeFactory
            ->method('create')
            ->willReturnOnConsecutiveCalls($storeMock1, $storeMock2);

        $this->assertEquals(
            ['some_code' => $storeMock1, 'some_code_2' => $storeMock2],
            $this->storeRepository->getList()
        );
    }
}
