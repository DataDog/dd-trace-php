<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CheckoutAgreements\Test\Unit\Block;

use Magento\CheckoutAgreements\Model\AgreementsProvider;
use Magento\Store\Model\ScopeInterface;

class AgreementsTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\CheckoutAgreements\Block\Agreements
     */
    protected $model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $agreementCollFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $scopeConfigMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $storeManagerMock;

    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->agreementCollFactoryMock = $this->createPartialMock(
            \Magento\CheckoutAgreements\Model\ResourceModel\Agreement\CollectionFactory::class,
            ['create']
        );

        $this->scopeConfigMock = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $this->storeManagerMock = $this->createMock(\Magento\Store\Model\StoreManagerInterface::class);

        $contextMock = $this->createMock(\Magento\Framework\View\Element\Template\Context::class);
        $contextMock->expects($this->once())->method('getScopeConfig')->willReturn($this->scopeConfigMock);
        $contextMock->expects($this->once())->method('getStoreManager')->willReturn($this->storeManagerMock);

        $this->model = $objectManager->getObject(
            \Magento\CheckoutAgreements\Block\Agreements::class,
            [
                'agreementCollectionFactory' => $this->agreementCollFactoryMock,
                'context' => $contextMock
            ]
        );
    }

    public function testGetAgreements()
    {
        $storeId = 100;
        $this->scopeConfigMock->expects($this->once())
            ->method('isSetFlag')
            ->with(AgreementsProvider::PATH_ENABLED, ScopeInterface::SCOPE_STORE)
            ->willReturn(true);

        $agreementCollection = $this->createMock(
            \Magento\CheckoutAgreements\Model\ResourceModel\Agreement\Collection::class
        );
        $this->agreementCollFactoryMock->expects($this->once())->method('create')->willReturn($agreementCollection);

        $storeMock = $this->createMock(\Magento\Store\Model\Store::class);
        $storeMock->expects($this->once())->method('getId')->willReturn($storeId);
        $this->storeManagerMock->expects($this->once())->method('getStore')->willReturn($storeMock);

        $agreementCollection->expects($this->once())->method('addStoreFilter')->with($storeId)->willReturnSelf();
        $agreementCollection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('is_active', 1)
            ->willReturnSelf();

        $this->assertEquals($agreementCollection, $this->model->getAgreements());
    }

    public function testGetAgreementsIfAgreementsDisabled()
    {
        $expectedResult = [];
        $this->scopeConfigMock->expects($this->once())
            ->method('isSetFlag')
            ->with(AgreementsProvider::PATH_ENABLED, ScopeInterface::SCOPE_STORE)
            ->willReturn(false);
        $this->assertEquals($expectedResult, $this->model->getAgreements());
    }
}
