<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Downloadable\Test\Unit\Observer;

use Magento\Downloadable\Observer\IsAllowedGuestCheckoutObserver;
use Magento\Downloadable\Model\Product\Type;
use Magento\Downloadable\Model\ResourceModel\Link\Purchased\Item\CollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class IsAllowedGuestCheckoutObserverTest extends \PHPUnit\Framework\TestCase
{
    /** @var IsAllowedGuestCheckoutObserver */
    private $isAllowedGuestCheckoutObserver;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\App\Config
     */
    private $scopeConfig;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\DataObject
     */
    private $resultMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\Event
     */
    private $eventMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\Event\Observer
     */
    private $observerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\DataObject
     */
    private $storeMock;

    /**
     * Sets up the fixture, for example, open a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp(): void
    {
        $this->scopeConfig = $this->getMockBuilder(\Magento\Framework\App\Config::class)
            ->disableOriginalConstructor()
            ->setMethods(['isSetFlag', 'getValue'])
            ->getMock();

        $this->resultMock = $this->getMockBuilder(\Magento\Framework\DataObject::class)
            ->disableOriginalConstructor()
            ->setMethods(['setIsAllowed'])
            ->getMock();

        $this->eventMock = $this->getMockBuilder(\Magento\Framework\Event::class)
            ->disableOriginalConstructor()
            ->setMethods(['getStore', 'getResult', 'getQuote', 'getOrder'])
            ->getMock();

        $this->observerMock = $this->getMockBuilder(\Magento\Framework\Event\Observer::class)
            ->disableOriginalConstructor()
            ->setMethods(['getEvent'])
            ->getMock();

        $this->storeMock = $this->getMockBuilder(\Magento\Framework\DataObject::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->isAllowedGuestCheckoutObserver = (new ObjectManagerHelper($this))->getObject(
            \Magento\Downloadable\Observer\IsAllowedGuestCheckoutObserver::class,
            [
                'scopeConfig' => $this->scopeConfig,
            ]
        );
    }

    /**
     *
     * @dataProvider dataProviderForTestisAllowedGuestCheckoutConfigSetToTrue
     *
     * @param $productType
     * @param $isAllowed
     */
    public function testIsAllowedGuestCheckoutConfigSetToTrue($productType, $isAllowed)
    {
        if ($isAllowed) {
            $this->resultMock->expects($this->at(0))
                ->method('setIsAllowed')
                ->with(false);
        }

        $product = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->disableOriginalConstructor()
            ->setMethods(['getTypeId'])
            ->getMock();

        $product->expects($this->once())
            ->method('getTypeId')
            ->willReturn($productType);

        $item = $this->getMockBuilder(\Magento\Quote\Model\Quote\Item::class)
            ->disableOriginalConstructor()
            ->setMethods(['getProduct'])
            ->getMock();

        $item->expects($this->once())
            ->method('getProduct')
            ->willReturn($product);

        $quote = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)
            ->disableOriginalConstructor()
            ->setMethods(['getAllItems'])
            ->getMock();

        $quote->expects($this->once())
            ->method('getAllItems')
            ->willReturn([$item]);

        $this->eventMock->expects($this->once())
            ->method('getStore')
            ->willReturn($this->storeMock);

        $this->eventMock->expects($this->once())
            ->method('getResult')
            ->willReturn($this->resultMock);

        $this->eventMock->expects($this->once())
            ->method('getQuote')
            ->willReturn($quote);

        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with(
                IsAllowedGuestCheckoutObserver::XML_PATH_DISABLE_GUEST_CHECKOUT,
                ScopeInterface::SCOPE_STORE,
                $this->storeMock
            )
            ->willReturn(true);

        $this->observerMock->expects($this->exactly(3))
            ->method('getEvent')
            ->willReturn($this->eventMock);

        $this->assertInstanceOf(
            \Magento\Downloadable\Observer\IsAllowedGuestCheckoutObserver::class,
            $this->isAllowedGuestCheckoutObserver->execute($this->observerMock)
        );
    }

    /**
     * @return array
     */
    public function dataProviderForTestisAllowedGuestCheckoutConfigSetToTrue()
    {
        return [
            1 => [Type::TYPE_DOWNLOADABLE, true],
            2 => ['unknown', false],
        ];
    }

    public function testIsAllowedGuestCheckoutConfigSetToFalse()
    {
        $this->eventMock->expects($this->once())
            ->method('getStore')
            ->willReturn($this->storeMock);

        $this->eventMock->expects($this->once())
            ->method('getResult')
            ->willReturn($this->resultMock);

        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with(
                IsAllowedGuestCheckoutObserver::XML_PATH_DISABLE_GUEST_CHECKOUT,
                ScopeInterface::SCOPE_STORE,
                $this->storeMock
            )
            ->willReturn(false);

        $this->observerMock->expects($this->exactly(2))
            ->method('getEvent')
            ->willReturn($this->eventMock);

        $this->assertInstanceOf(
            \Magento\Downloadable\Observer\IsAllowedGuestCheckoutObserver::class,
            $this->isAllowedGuestCheckoutObserver->execute($this->observerMock)
        );
    }
}
