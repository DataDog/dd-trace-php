<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Quote\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\ExtensionAttribute\JoinProcessorInterface;
use PHPUnit\Framework\MockObject\Rule\InvokedCount as InvokedCountMatch;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartInterfaceFactory;
use Magento\Quote\Api\Data\CartSearchResultsInterface;
use Magento\Quote\Api\Data\CartSearchResultsInterfaceFactory;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteRepository;
use Magento\Quote\Model\QuoteRepository\LoadHandler;
use Magento\Quote\Model\QuoteRepository\SaveHandler;
use Magento\Quote\Model\ResourceModel\Quote\Collection;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
class QuoteRepositoryTest extends TestCase
{
    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    private $model;

    /**
     * @var CartInterfaceFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $cartFactoryMock;

    /**
     * @var StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $storeManagerMock;

    /**
     * @var Store|\PHPUnit\Framework\MockObject\MockObject
     */
    private $storeMock;

    /**
     * @var Quote|\PHPUnit\Framework\MockObject\MockObject
     */
    private $quoteMock;

    /**
     * @var CartSearchResultsInterfaceFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $searchResultsDataFactory;

    /**
     * @var Collection|\PHPUnit\Framework\MockObject\MockObject
     */
    private $quoteCollectionMock;

    /**
     * @var JoinProcessorInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $extensionAttributesJoinProcessorMock;

    /**
     * @var LoadHandler|\PHPUnit\Framework\MockObject\MockObject
     */
    private $loadHandlerMock;

    /**
     * @var LoadHandler|\PHPUnit\Framework\MockObject\MockObject
     */
    private $saveHandlerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $collectionProcessor;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $objectManagerMock;

    /**
     * @var CollectionFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $quoteCollectionFactoryMock;

    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);

        $this->objectManagerMock = $this->getMockForAbstractClass(ObjectManagerInterface::class);
        \Magento\Framework\App\ObjectManager::setInstance($this->objectManagerMock);

        $this->cartFactoryMock = $this->createPartialMock(CartInterfaceFactory::class, ['create']);
        $this->storeManagerMock = $this->getMockForAbstractClass(StoreManagerInterface::class);
        $this->quoteMock = $this->createPartialMock(
            Quote::class,
            [
                'load',
                'loadByIdWithoutStore',
                'loadByCustomer',
                'getIsActive',
                'getId',
                '__wakeup',
                'setSharedStoreIds',
                'save',
                'delete',
                'getCustomerId',
                'getStoreId',
                'getData'
            ]
        );
        $this->storeMock = $this->createMock(Store::class);
        $this->searchResultsDataFactory = $this->createPartialMock(
            CartSearchResultsInterfaceFactory::class,
            ['create']
        );

        $this->quoteCollectionMock =
            $this->createMock(Collection::class);

        $this->extensionAttributesJoinProcessorMock = $this->createMock(
            JoinProcessorInterface::class
        );
        $this->collectionProcessor = $this->createMock(
            CollectionProcessorInterface::class
        );
        $this->quoteCollectionFactoryMock = $this->createPartialMock(
            CollectionFactory::class,
            ['create']
        );
        $this->model = $objectManager->getObject(
            QuoteRepository::class,
            [
                'storeManager' => $this->storeManagerMock,
                'searchResultsDataFactory' => $this->searchResultsDataFactory,
                'quoteCollection' => $this->quoteCollectionMock,
                'extensionAttributesJoinProcessor' => $this->extensionAttributesJoinProcessorMock,
                'collectionProcessor' => $this->collectionProcessor,
                'quoteCollectionFactory' => $this->quoteCollectionFactoryMock,
                'cartFactory' => $this->cartFactoryMock
            ]
        );

        $this->loadHandlerMock = $this->createMock(LoadHandler::class);
        $this->saveHandlerMock = $this->createMock(SaveHandler::class);

        $reflection = new \ReflectionClass(get_class($this->model));
        $reflectionProperty = $reflection->getProperty('loadHandler');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->model, $this->loadHandlerMock);

        $reflectionProperty = $reflection->getProperty('saveHandler');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->model, $this->saveHandlerMock);
    }

    /**
     */
    public function testGetWithExceptionById()
    {
        $this->expectException(\Magento\Framework\Exception\NoSuchEntityException::class);
        $this->expectExceptionMessage('No such entity with cartId = 14');

        $cartId = 14;

        $this->cartFactoryMock->expects($this->once())->method('create')->willReturn($this->quoteMock);
        $this->storeManagerMock->expects($this->once())->method('getStore')->willReturn($this->storeMock);
        $this->storeMock->expects($this->once())->method('getId')->willReturn(1);
        $this->quoteMock->expects($this->never())->method('setSharedStoreIds');
        $this->quoteMock->expects($this->once())
            ->method('loadByIdWithoutStore')
            ->with($cartId)
            ->willReturn($this->storeMock);
        $this->quoteMock->expects($this->once())->method('getId')->willReturn(false);

        $this->model->get($cartId);
    }

    public function testGet()
    {
        $cartId = 15;

        $this->cartFactoryMock->expects(static::once())
            ->method('create')
            ->willReturn($this->quoteMock);
        $this->storeManagerMock->expects(static::once())
            ->method('getStore')
            ->willReturn($this->storeMock);
        $this->storeMock->expects(static::once())
            ->method('getId')
            ->willReturn(1);
        $this->quoteMock->expects(static::never())
            ->method('setSharedStoreIds');
        $this->quoteMock->expects(static::once())
            ->method('loadByIdWithoutStore')
            ->with($cartId)
            ->willReturn($this->storeMock);
        $this->quoteMock->expects(static::once())
            ->method('getId')
            ->willReturn($cartId);
        $this->quoteMock->expects(static::never())
            ->method('getCustomerId');
        $this->loadHandlerMock->expects(static::once())
            ->method('load')
            ->with($this->quoteMock);

        static::assertEquals($this->quoteMock, $this->model->get($cartId));
        static::assertEquals($this->quoteMock, $this->model->get($cartId));
    }

    public function testGetForCustomerAfterGet()
    {
        $cartId = 15;
        $customerId = 23;

        $this->cartFactoryMock->expects(static::exactly(2))
            ->method('create')
            ->willReturn($this->quoteMock);
        $this->storeManagerMock->expects(static::exactly(2))
            ->method('getStore')
            ->willReturn($this->storeMock);
        $this->storeMock->expects(static::exactly(2))
            ->method('getId')
            ->willReturn(1);
        $this->quoteMock->expects(static::never())
            ->method('setSharedStoreIds');
        $this->quoteMock->expects(static::once())
            ->method('loadByIdWithoutStore')
            ->with($cartId)
            ->willReturn($this->storeMock);
        $this->quoteMock->expects(static::once())
            ->method('loadByCustomer')
            ->with($customerId)
            ->willReturn($this->storeMock);
        $this->quoteMock->expects(static::exactly(3))
            ->method('getId')
            ->willReturn($cartId);
        $this->quoteMock->expects(static::any())
            ->method('getCustomerId')
            ->willReturn($customerId);
        $this->loadHandlerMock->expects(static::exactly(2))
            ->method('load')
            ->with($this->quoteMock);

        static::assertEquals($this->quoteMock, $this->model->get($cartId));
        static::assertEquals($this->quoteMock, $this->model->getForCustomer($customerId));
    }

    public function testGetWithSharedStoreIds()
    {
        $cartId = 16;
        $sharedStoreIds = [1, 2];

        $this->cartFactoryMock->expects($this->once())->method('create')->willReturn($this->quoteMock);
        $this->storeManagerMock->expects($this->once())->method('getStore')->willReturn($this->storeMock);
        $this->storeMock->expects($this->once())->method('getId')->willReturn(1);
        $this->quoteMock->expects($this->once())
            ->method('setSharedStoreIds')
            ->with($sharedStoreIds)
            ->willReturnSelf();
        $this->quoteMock->expects($this->once())
            ->method('loadByIdWithoutStore')
            ->with($cartId)
            ->willReturn($this->storeMock);
        $this->quoteMock->expects($this->once())->method('getId')->willReturn($cartId);

        $this->loadHandlerMock->expects($this->once())
            ->method('load')
            ->with($this->quoteMock)
            ->willReturn($this->quoteMock);

        $this->assertEquals($this->quoteMock, $this->model->get($cartId, $sharedStoreIds));
    }

    /**
     * Test getForCustomer method
     *
     * @param InvokedCountMatch $invokeTimes
     * @param array $sharedStoreIds
     * @dataProvider getForCustomerDataProvider
     */
    public function testGetForCustomer(InvokedCountMatch $invokeTimes, array $sharedStoreIds)
    {
        $cartId = 17;
        $customerId = 23;

        $this->cartFactoryMock->expects(static::once())
            ->method('create')
            ->willReturn($this->quoteMock);
        $this->storeManagerMock->expects(static::once())
            ->method('getStore')
            ->willReturn($this->storeMock);
        $this->storeMock->expects(static::once())
            ->method('getId')
            ->willReturn(1);
        $this->quoteMock->expects($invokeTimes)
            ->method('setSharedStoreIds');
        $this->quoteMock->expects(static::once())
            ->method('loadByCustomer')
            ->with($customerId)
            ->willReturn($this->storeMock);
        $this->quoteMock->expects(static::exactly(2))
            ->method('getId')
            ->willReturn($cartId);

        $this->loadHandlerMock->expects(static::once())
            ->method('load')
            ->with($this->quoteMock);

        static::assertEquals($this->quoteMock, $this->model->getForCustomer($customerId, $sharedStoreIds));
        static::assertEquals($this->quoteMock, $this->model->getForCustomer($customerId));
    }

    /**
     * Checking how many times we invoke setSharedStoreIds() in protected method loadQuote()
     *
     * @return array
     */
    public function getForCustomerDataProvider()
    {
        return [
            [
                'invoke_number_times' => static::never(),
                'shared_store_ids' => []
            ],
            [
                'invoke_number_times' => static::once(),
                'shared_store_ids' => [1]
            ]
        ];
    }

    /**
     */
    public function testGetActiveWithExceptionById()
    {
        $this->expectException(\Magento\Framework\Exception\NoSuchEntityException::class);
        $this->expectExceptionMessage('No such entity with cartId = 14');

        $cartId = 14;

        $this->cartFactoryMock->expects($this->once())->method('create')->willReturn($this->quoteMock);
        $this->storeManagerMock->expects($this->once())->method('getStore')->willReturn($this->storeMock);
        $this->storeMock->expects($this->once())->method('getId')->willReturn(1);
        $this->quoteMock->expects($this->never())->method('setSharedStoreIds');
        $this->quoteMock->expects($this->once())
            ->method('loadByIdWithoutStore')
            ->with($cartId)
            ->willReturn($this->storeMock);
        $this->quoteMock->expects($this->once())->method('getId')->willReturn(false);
        $this->quoteMock->expects($this->never())->method('getIsActive');

        $this->model->getActive($cartId);
    }

    /**
     */
    public function testGetActiveWithExceptionByIsActive()
    {
        $this->expectException(\Magento\Framework\Exception\NoSuchEntityException::class);
        $this->expectExceptionMessage('No such entity with cartId = 15');

        $cartId = 15;

        $this->cartFactoryMock->expects($this->once())->method('create')->willReturn($this->quoteMock);
        $this->storeManagerMock->expects($this->once())->method('getStore')->willReturn($this->storeMock);
        $this->storeMock->expects($this->once())->method('getId')->willReturn(1);
        $this->quoteMock->expects($this->never())->method('setSharedStoreIds');
        $this->quoteMock->expects($this->once())
            ->method('loadByIdWithoutStore')
            ->with($cartId)
            ->willReturn($this->storeMock);
        $this->quoteMock->expects($this->once())->method('getId')->willReturn($cartId);
        $this->quoteMock->expects($this->once())->method('getIsActive')->willReturn(0);

        $this->loadHandlerMock->expects($this->once())
            ->method('load')
            ->with($this->quoteMock)
            ->willReturn($this->quoteMock);

        $this->model->getActive($cartId);
    }

    public function testGetActive()
    {
        $cartId = 15;

        $this->cartFactoryMock->expects($this->once())->method('create')->willReturn($this->quoteMock);
        $this->storeManagerMock->expects($this->once())->method('getStore')->willReturn($this->storeMock);
        $this->storeMock->expects($this->once())->method('getId')->willReturn(1);
        $this->quoteMock->expects($this->never())->method('setSharedStoreIds');
        $this->quoteMock->expects($this->once())
            ->method('loadByIdWithoutStore')
            ->with($cartId)
            ->willReturn($this->storeMock);
        $this->quoteMock->expects($this->once())->method('getId')->willReturn($cartId);
        $this->quoteMock->expects($this->once())->method('getIsActive')->willReturn(1);

        $this->loadHandlerMock->expects($this->once())
            ->method('load')
            ->with($this->quoteMock)
            ->willReturn($this->quoteMock);

        $this->assertEquals($this->quoteMock, $this->model->getActive($cartId));
    }

    public function testGetActiveWithSharedStoreIds()
    {
        $cartId = 16;
        $sharedStoreIds = [1, 2];

        $this->cartFactoryMock->expects($this->once())->method('create')->willReturn($this->quoteMock);
        $this->storeManagerMock->expects($this->once())->method('getStore')->willReturn($this->storeMock);
        $this->storeMock->expects($this->once())->method('getId')->willReturn(1);
        $this->quoteMock->expects($this->once())
            ->method('setSharedStoreIds')
            ->with($sharedStoreIds)
            ->willReturnSelf();
        $this->quoteMock->expects($this->once())
            ->method('loadByIdWithoutStore')
            ->with($cartId)
            ->willReturn($this->storeMock);
        $this->quoteMock->expects($this->once())->method('getId')->willReturn($cartId);
        $this->quoteMock->expects($this->once())->method('getIsActive')->willReturn(1);

        $this->loadHandlerMock->expects($this->once())
            ->method('load')
            ->with($this->quoteMock)
            ->willReturn($this->quoteMock);

        $this->assertEquals($this->quoteMock, $this->model->getActive($cartId, $sharedStoreIds));
    }

    public function testGetActiveForCustomer()
    {
        $cartId = 17;
        $customerId = 23;

        $this->cartFactoryMock->expects($this->once())->method('create')->willReturn($this->quoteMock);
        $this->storeManagerMock->expects($this->once())->method('getStore')->willReturn($this->storeMock);
        $this->storeMock->expects($this->once())->method('getId')->willReturn(1);
        $this->quoteMock->expects($this->never())->method('setSharedStoreIds');
        $this->quoteMock->expects($this->once())
            ->method('loadByCustomer')
            ->with($customerId)
            ->willReturn($this->storeMock);
        $this->quoteMock->expects($this->exactly(2))->method('getId')->willReturn($cartId);
        $this->quoteMock->expects($this->exactly(2))->method('getIsActive')->willReturn(1);

        $this->loadHandlerMock->expects($this->once())
            ->method('load')
            ->with($this->quoteMock)
            ->willReturn($this->quoteMock);

        $this->assertEquals($this->quoteMock, $this->model->getActiveForCustomer($customerId));
        $this->assertEquals($this->quoteMock, $this->model->getActiveForCustomer($customerId));
    }

    public function testSave()
    {
        $cartId = 100;
        $quoteMock = $this->createPartialMock(
            Quote::class,
            ['getId', 'getCustomerId', 'getStoreId', 'hasData', 'setData']
        );
        $quoteMock->expects($this->exactly(3))->method('getId')->willReturn($cartId);
        $quoteMock->expects($this->once())->method('getCustomerId')->willReturn(2);
        $quoteMock->expects($this->once())->method('getStoreId')->willReturn(5);

        $this->cartFactoryMock->expects($this->once())->method('create')->willReturn($this->quoteMock);
        $this->storeManagerMock->expects($this->once())->method('getStore')->willReturn($this->storeMock);
        $this->storeMock->expects($this->once())->method('getId')->willReturn(1);
        $this->quoteMock->expects($this->once())->method('getId')->willReturn($cartId);
        $this->quoteMock->expects($this->once())->method('setSharedStoreIds');
        $this->quoteMock->expects($this->once())
            ->method('loadByIdWithoutStore')
            ->with($cartId)
            ->willReturn($this->storeMock);
        $this->loadHandlerMock->expects($this->once())
            ->method('load')
            ->with($this->quoteMock)
            ->willReturn($this->quoteMock);

        $this->quoteMock->expects($this->once())->method('getData')->willReturn(['key' => 'value']);

        $quoteMock->expects($this->once())->method('hasData')->with('key')->willReturn(false);
        $quoteMock->expects($this->once())->method('setData')->with('key', 'value')->willReturnSelf();

        $this->saveHandlerMock->expects($this->once())->method('save')->with($quoteMock)->willReturn($quoteMock);
        $this->model->save($quoteMock);
    }

    public function testDelete()
    {
        $this->quoteMock->expects($this->once())
            ->method('delete');
        $this->quoteMock->expects($this->exactly(1))->method('getId')->willReturn(1);
        $this->quoteMock->expects($this->exactly(1))->method('getCustomerId')->willReturn(2);

        $this->model->delete($this->quoteMock);
    }

    public function testGetList()
    {
        $pageSize = 10;
        $this->quoteCollectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->quoteCollectionMock);
        $cartMock = $this->getMockForAbstractClass(CartInterface::class);
        $this->loadHandlerMock->expects($this->once())
            ->method('load')
            ->with($cartMock);

        $searchResult = $this->getMockForAbstractClass(CartSearchResultsInterface::class);
        $searchCriteriaMock = $this->createMock(SearchCriteria::class);
        $this->searchResultsDataFactory
            ->expects($this->once())
            ->method('create')
            ->willReturn($searchResult);

        $this->collectionProcessor->expects($this->once())
            ->method('process')
            ->with($searchCriteriaMock, $this->quoteCollectionMock);

        $this->extensionAttributesJoinProcessorMock->expects($this->once())
            ->method('process')
            ->with(
                $this->isInstanceOf(Collection::class)
            );
        $this->quoteCollectionMock->expects($this->atLeastOnce())->method('getItems')->willReturn([$cartMock]);
        $searchResult->expects($this->once())->method('setTotalCount')->with($pageSize);
        $this->quoteCollectionMock->expects($this->once())
            ->method('getSize')
            ->willReturn($pageSize);
        $searchResult->expects($this->once())
            ->method('setItems')
            ->with([$cartMock]);
        $this->assertEquals($searchResult, $this->model->getList($searchCriteriaMock));
    }

    /**
     * @deprecated
     * @return array
     */
    public function getListSuccessDataProvider()
    {
        return [
            'asc' => [SortOrder::SORT_ASC, 'ASC'],
            'desc' => [SortOrder::SORT_DESC, 'DESC']
        ];
    }
}
