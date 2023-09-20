<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Paypal\Test\Unit\Block\Billing;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AgreementsTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\Element\Context|\PHPUnit\Framework\MockObject\MockObject
     */
    private $context;

    /**
     * @codingStandardsIgnoreStart
     * @var \Magento\Paypal\Model\ResourceModel\Billing\Agreement\CollectionFactory|\PHPUnit\Framework\MockObject\MockObject
     * @codingStandardsIgnoreEnd
     */
    private $agreementCollection;

    /**
     * @var \Magento\Framework\UrlInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $urlBuilder;

    /**
     * @var \Magento\Framework\Escaper|\PHPUnit\Framework\MockObject\MockObject
     */
    private $escaper;

    /**
     * @var \Magento\Paypal\Helper\Data|\PHPUnit\Framework\MockObject\MockObject
     */
    private $helper;

    /**
     * @var \Magento\Framework\View\LayoutInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $layout;

    /**
     * @var \Magento\Framework\Event\ManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $eventManager;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $scopeConfig;

    /**
     * @var \Magento\Framework\App\CacheInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $cache;

    /**
     * @var \Magento\Paypal\Block\Billing\Agreements
     */
    private $block;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->context = $this->createMock(\Magento\Framework\View\Element\Template\Context::class);
        $this->escaper = $this->createMock(\Magento\Framework\Escaper::class);
        $this->context->expects($this->once())->method('getEscaper')->willReturn($this->escaper);
        $localeDate = $this->getMockForAbstractClass(
            \Magento\Framework\Stdlib\DateTime\TimezoneInterface::class,
            [],
            '',
            false
        );
        $this->context->expects($this->once())->method('getLocaleDate')->willReturn($localeDate);
        $this->urlBuilder = $this->getMockForAbstractClass(\Magento\Framework\UrlInterface::class, [], '', false);
        $this->context->expects($this->once())->method('getUrlBuilder')->willReturn($this->urlBuilder);
        $this->layout = $this->getMockForAbstractClass(\Magento\Framework\View\LayoutInterface::class, [], '', false);
        $this->context->expects($this->once())->method('getLayout')->willReturn($this->layout);
        $this->eventManager = $this->getMockForAbstractClass(
            \Magento\Framework\Event\ManagerInterface::class,
            [],
            '',
            false
        );
        $this->context->expects($this->once())->method('getEventManager')->willReturn($this->eventManager);
        $this->scopeConfig = $this->getMockForAbstractClass(
            \Magento\Framework\App\Config\ScopeConfigInterface::class,
            [],
            '',
            false
        );
        $this->context->expects($this->once())->method('getScopeConfig')->willReturn($this->scopeConfig);
        $this->cache = $this->getMockForAbstractClass(\Magento\Framework\App\CacheInterface::class, [], '', false);
        $this->context->expects($this->once())->method('getCache')->willReturn($this->cache);
        $this->agreementCollection = $this->getMockBuilder(
            \Magento\Paypal\Model\ResourceModel\Billing\Agreement\CollectionFactory::class
        )->disableOriginalConstructor()->setMethods(['create'])->getMock();
        $this->helper = $this->createMock(\Magento\Paypal\Helper\Data::class);
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->block = $objectManager->getObject(
            \Magento\Paypal\Block\Billing\Agreements::class,
            [
                'context' => $this->context,
                'agreementCollection' => $this->agreementCollection,
                'helper' => $this->helper,
            ]
        );
    }

    public function testGetBillingAgreements()
    {
        $collection = $this->createMock(\Magento\Paypal\Model\ResourceModel\Billing\Agreement\Collection::class);
        $this->agreementCollection->expects($this->once())->method('create')->willReturn($collection);
        $collection->expects($this->once())->method('addFieldToFilter')->willReturn($collection);
        $collection->expects($this->once())->method('setOrder')->willReturn($collection);
        $this->assertSame($collection, $this->block->getBillingAgreements());
        // call second time to make sure mock only called once
        $this->block->getBillingAgreements();
    }

    public function testGetItemValueCreatedAt()
    {
        $this->escaper->expects($this->once())->method('escapeHtml');
        $item = $this->createMock(\Magento\Paypal\Model\Billing\Agreement::class);
        $item->expects($this->exactly(2))->method('getData')->with('created_at')->willReturn('03/10/2014');
        $this->block->getItemValue($item, 'created_at');
    }

    public function testGetItemValueCreatedAtNoData()
    {
        $this->escaper->expects($this->once())->method('escapeHtml');
        $item = $this->createMock(\Magento\Paypal\Model\Billing\Agreement::class);
        $item->expects($this->once())->method('getData')->with('created_at')->willReturn(false);
        $this->block->getItemValue($item, 'created_at');
    }

    public function testGetItemValueUpdatedAt()
    {
        $this->escaper->expects($this->once())->method('escapeHtml');
        $item = $this->createMock(\Magento\Paypal\Model\Billing\Agreement::class);
        $item->expects($this->exactly(2))->method('getData')->with('updated_at')->willReturn('03/10/2014');
        $this->block->getItemValue($item, 'updated_at');
    }

    public function testGetItemValueUpdatedAtNoData()
    {
        $this->escaper->expects($this->once())->method('escapeHtml');
        $item = $this->createMock(\Magento\Paypal\Model\Billing\Agreement::class);
        $item->expects($this->once())->method('getData')->with('updated_at')->willReturn(false);
        $this->block->getItemValue($item, 'updated_at');
    }

    public function testGetItemValueEditUrl()
    {
        $this->escaper->expects($this->once())->method('escapeHtml');
        $item = $this->createPartialMock(\Magento\Paypal\Model\Billing\Agreement::class, ['getAgreementId']);
        $item->expects($this->once())->method('getAgreementId')->willReturn(1);
        $this->urlBuilder
            ->expects($this->once())
            ->method('getUrl')
            ->with('paypal/billing_agreement/view', ['agreement' => 1]);
        $this->block->getItemValue($item, 'edit_url');
    }

    public function testGetItemPaymentMethodLabel()
    {
        $this->escaper->expects($this->once())->method('escapeHtml')->with('label', null);
        $item = $this->createPartialMock(\Magento\Paypal\Model\Billing\Agreement::class, ['getAgreementLabel']);
        $item->expects($this->once())->method('getAgreementLabel')->willReturn('label');
        $this->block->getItemValue($item, 'payment_method_label');
    }

    public function testGetItemStatus()
    {
        $this->escaper->expects($this->once())->method('escapeHtml')->with('status', null);
        $item = $this->createMock(\Magento\Paypal\Model\Billing\Agreement::class);
        $item->expects($this->once())->method('getStatusLabel')->willReturn('status');
        $this->block->getItemValue($item, 'status');
    }

    public function testGetItemDefault()
    {
        $this->escaper->expects($this->once())->method('escapeHtml')->with('value', null);
        $item = $this->createMock(\Magento\Paypal\Model\Billing\Agreement::class);
        $item->expects($this->exactly(2))->method('getData')->with('default')->willReturn('value');
        $this->block->getItemValue($item, 'default');
    }

    public function testGetWizardPaymentMethodOptions()
    {
        $method1 = $this->createPartialMock(
            \Magento\Paypal\Model\Method\Agreement::class,
            ['getConfigData', 'getCode', 'getTitle']
        );
        $method2 = $this->createPartialMock(
            \Magento\Paypal\Model\Method\Agreement::class,
            ['getConfigData', 'getCode', 'getTitle']
        );
        $method3 = $this->createPartialMock(
            \Magento\Paypal\Model\Method\Agreement::class,
            ['getConfigData', 'getCode', 'getTitle']
        );
        $method1->expects($this->once())->method('getCode')->willReturn('code1');
        $method2->expects($this->never())->method('getCode');
        $method3->expects($this->once())->method('getCode')->willReturn('code3');
        $method1->expects($this->once())->method('getTitle')->willReturn('title1');
        $method2->expects($this->never())->method('getTitle');
        $method3->expects($this->once())->method('getTitle')->willReturn('title3');
        $method1->expects($this->any())->method('getConfigData')->willReturn(1);
        $method2->expects($this->any())->method('getConfigData')->willReturn(0);
        $method3->expects($this->any())->method('getConfigData')->willReturn(1);
        $paymentMethods = [$method1, $method2, $method3];
        $this->helper->expects($this->once())->method('getBillingAgreementMethods')->willReturn($paymentMethods);
        $this->assertEquals(['code1' => 'title1', 'code3' => 'title3'], $this->block->getWizardPaymentMethodOptions());
    }

    public function testToHtml()
    {
        $this->eventManager
            ->expects($this->at(0))
            ->method('dispatch')
            ->with('view_block_abstract_to_html_before', ['block' => $this->block]);
        $transport = new \Magento\Framework\DataObject(['html' => '']);
        $this->eventManager
            ->expects($this->at(1))
            ->method('dispatch')
            ->with('view_block_abstract_to_html_after', ['block' => $this->block, 'transport' => $transport]);
        $this->scopeConfig
            ->expects($this->once())
            ->method('getValue')
            ->willReturn(false);
        $this->urlBuilder->expects($this->once())->method('getUrl')->with('paypal/billing_agreement/startWizard', []);
        $this->block->toHtml();
    }
}
