<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Controller\Adminhtml\Product\Attribute;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class EditTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Controller\Adminhtml\Product\Attribute\Edit
     */
    protected $editController;

    /**
     * @var \Magento\Framework\App\RequestInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $request;

    /**
     * @var \Magento\Framework\ObjectManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectManagerMock;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $eavAttribute;

    /**
     * @var \Magento\Framework\Registry|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $registry;

    /**
     * @var \Magento\Backend\Model\View\Result\Page|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultPage;

    /**
     * @var  \Magento\Framework\View\Result\Layout|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultLayout;

    /**
     * @var \Magento\Framework\View\Page\Config|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $pageConfig;

    /**
     * @var \Magento\Framework\View\Layout|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $layout;

    /**
     * @var \Magento\Backend\Model\Session|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $session;

    /**
     * @var \Magento\Catalog\Model\Product\Attribute\Frontend\Inputtype\Presentation|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $presentation;

    /**
     * @var \Magento\Framework\View\Page\Title|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $pageTitle;

    /**
     * @var \Magento\Backend\Block\Template|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $blockTemplate;

    /**
     * @var \Magento\Backend\App\Action\Context|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $context;

    /**
     * @var \Magento\Framework\View\Result\PageFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultPageFactory;

    protected function setUp(): void
    {
        $this->request = $this->getMockBuilder(\Magento\Framework\App\RequestInterface::class)->getMock();

        $this->objectManagerMock = $this->getMockBuilder(\Magento\Framework\ObjectManagerInterface::class)->getMock();

        $this->eavAttribute = $this->createPartialMock(
            \Magento\Catalog\Model\ResourceModel\Eav\Attribute::class,
            ['setEntityTypeId', 'load', 'getId', 'getEntityTypeId', 'addData', 'getName']
        );

        $this->registry = $this->createMock(\Magento\Framework\Registry::class);

        $this->resultPage = $this->getMockBuilder(\Magento\Backend\Model\View\Result\Page::class)
            ->disableOriginalConstructor()
            ->setMethods(['setActiveMenu', 'getConfig', 'addBreadcrumb', 'addHandle', 'getLayout'])
            ->getMock();

        $this->resultPageFactory = $this->getMockBuilder(\Magento\Framework\View\Result\PageFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $this->resultLayout = $this->getMockBuilder(\Magento\Framework\View\Result\Layout::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->pageConfig = $this->getMockBuilder(\Magento\Framework\View\Page\Config::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->pageTitle = $this->getMockBuilder(\Magento\Framework\View\Page\Title::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->layout = $this->createPartialMock(\Magento\Framework\View\Layout::class, ['getBlock']);

        $this->session = $this->getMockBuilder(\Magento\Backend\Model\Session::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->presentation = $this->getMockBuilder(
            \Magento\Catalog\Model\Product\Attribute\Frontend\Inputtype\Presentation::class
        )->disableOriginalConstructor()
            ->getMock();

        $this->blockTemplate = $this->getMockBuilder(\Magento\Backend\Block\Template::class)
            ->setMethods(['setIsPopup'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->context = $this->createPartialMock(
            \Magento\Backend\App\Action\Context::class,
            ['getRequest', 'getObjectManager', 'getResultPageFactory', 'getSession']
        );
        $this->context->expects($this->any())->method('getRequest')->willReturn($this->request);
        $this->context->expects($this->any())->method('getObjectManager')->willReturn($this->objectManagerMock);
        $this->context->expects($this->any())->method('getResultPageFactory')->willReturn($this->resultPageFactory);
        $this->context->expects($this->any())->method('getSession')->willReturn($this->session);

        $objectManager = new ObjectManager($this);
        $this->editController = $objectManager->getObject(
            \Magento\Catalog\Controller\Adminhtml\Product\Attribute\Edit::class,
            [
                'context' => $this->context,
                'resultPageFactory' => $this->resultPageFactory
            ]
        );
    }

    public function testExecutePopup()
    {
        $attributesData = ['frontend_label' => ''];

        $this->request->expects($this->any())->method('getParam')->willReturnMap(
            [
                ['attribute_id', null, null],
                ['attribute', null, $attributesData],
                ['popup', null, '1'],
                ['product_tab', null, null]
            ]
        );

        $this->objectManagerMock->expects($this->any())->method('create')
            ->with(\Magento\Catalog\Model\ResourceModel\Eav\Attribute::class)
            ->willReturn($this->eavAttribute);
        $this->objectManagerMock->expects($this->any())->method('get')
            ->willReturnMap([
                [\Magento\Backend\Model\Session::class, $this->session],
                [\Magento\Catalog\Model\Product\Attribute\Frontend\Inputtype\Presentation::class, $this->presentation]
            ]);
        $this->eavAttribute->expects($this->once())->method('setEntityTypeId')->willReturnSelf();
        $this->eavAttribute->expects($this->once())->method('addData')->with($attributesData)->willReturnSelf();
        $this->eavAttribute->expects($this->any())->method('getName')->willReturn(null);

        $this->registry->expects($this->any())
            ->method('register')
            ->with('entity_attribute', $this->eavAttribute);

        $this->resultPage->expects($this->once())
            ->method('addHandle')
            ->with(['popup', 'catalog_product_attribute_edit_popup'])
            ->willReturnSelf();
        $this->resultPage->expects($this->any())->method('getConfig')->willReturn($this->pageConfig);
        $this->resultPage->expects($this->once())->method('getLayout')->willReturn($this->layout);

        $this->resultPageFactory->expects($this->atLeastOnce())
            ->method('create')
            ->willReturn($this->resultPage);

        $this->pageConfig->expects($this->any())->method('addBodyClass')->willReturnSelf();
        $this->pageConfig->expects($this->any())->method('getTitle')->willReturn($this->pageTitle);

        $this->pageTitle->expects($this->any())->method('prepend')->willReturnSelf();

        $this->layout->expects($this->once())->method('getBlock')->willReturn($this->blockTemplate);

        $this->blockTemplate->expects($this->any())->method('setIsPopup')->willReturnSelf();

        $this->assertSame($this->resultPage, $this->editController->execute());
    }

    public function testExecuteNoPopup()
    {
        $attributesData = ['frontend_label' => ''];

        $this->request->expects($this->any())->method('getParam')->willReturnMap(
            [
                ['attribute_id', null, null],
                ['attribute', null, $attributesData],
                ['popup', null, false],
            ]
        );

        $this->objectManagerMock->expects($this->any())->method('create')
            ->with(\Magento\Catalog\Model\ResourceModel\Eav\Attribute::class)
            ->willReturn($this->eavAttribute);
        $this->objectManagerMock->expects($this->any())->method('get')
            ->willReturnMap([
                [\Magento\Backend\Model\Session::class, $this->session],
                [\Magento\Catalog\Model\Product\Attribute\Frontend\Inputtype\Presentation::class, $this->presentation]
            ]);

        $this->eavAttribute->expects($this->once())->method('setEntityTypeId')->willReturnSelf();
        $this->eavAttribute->expects($this->once())->method('addData')->with($attributesData)->willReturnSelf();

        $this->registry->expects($this->any())
            ->method('register')
            ->with('entity_attribute', $this->eavAttribute);

        $this->resultPage->expects($this->any())->method('addBreadcrumb')->willReturnSelf();
        $this->resultPage->expects($this->once())
            ->method('setActiveMenu')
            ->with('Magento_Catalog::catalog_attributes_attributes')
            ->willReturnSelf();
        $this->resultPage->expects($this->any())->method('getConfig')->willReturn($this->pageConfig);
        $this->resultPage->expects($this->once())->method('getLayout')->willReturn($this->layout);

        $this->resultPageFactory->expects($this->atLeastOnce())
            ->method('create')
            ->willReturn($this->resultPage);

        $this->pageConfig->expects($this->any())->method('getTitle')->willReturn($this->pageTitle);

        $this->pageTitle->expects($this->any())->method('prepend')->willReturnSelf();

        $this->eavAttribute->expects($this->any())->method('getName')->willReturn(null);

        $this->layout->expects($this->once())->method('getBlock')->willReturn($this->blockTemplate);

        $this->blockTemplate->expects($this->any())->method('setIsPopup')->willReturnSelf();

        $this->assertSame($this->resultPage, $this->editController->execute());
    }
}
