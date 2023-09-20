<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Test\Unit\Controller\Adminhtml\Index;

/**
 * @covers \Magento\Customer\Controller\Adminhtml\Index\Index
 */
class IndexTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Customer\Controller\Adminhtml\Index\Index
     */
    protected $indexController;

    /**
     * @var \Magento\Backend\App\Action\Context
     */
    protected $context;

    /**
     * @var \Magento\Framework\App\Request|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $requestMock;

    /**
     * @var \Magento\Backend\Model\View\Result\ForwardFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultForwardFactoryMock;

    /**
     * @var \Magento\Backend\Model\View\Result\Forward|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultForwardMock;

    /**
     * @var \Magento\Framework\View\Result\PageFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultPageFactoryMock;

    /**
     * @var \Magento\Framework\View\Result\Page|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultPageMock;

    /**
     * @var \Magento\Framework\View\Page\Config|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $pageConfigMock;

    /**
     * @var \Magento\Framework\View\Page\Title|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $pageTitleMock;

    /**
     * @var \Magento\Backend\Model\Session|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $sessionMock;

    protected function setUp(): void
    {
        $this->requestMock = $this->getMockBuilder(\Magento\Framework\App\Request\Http::class)
            ->disableOriginalConstructor()->getMock();
        $this->resultForwardFactoryMock = $this->getMockBuilder(
            \Magento\Backend\Model\View\Result\ForwardFactory::class
        )
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->resultForwardMock = $this->getMockBuilder(\Magento\Backend\Model\View\Result\Forward::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resultPageFactoryMock = $this->getMockBuilder(\Magento\Framework\View\Result\PageFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resultPageMock = $this->getMockBuilder(\Magento\Framework\View\Result\Page::class)
            ->disableOriginalConstructor()
            ->setMethods(['setActiveMenu', 'getConfig', 'addBreadcrumb'])
            ->getMock();
        $this->pageConfigMock = $this->getMockBuilder(\Magento\Framework\View\Page\Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->pageTitleMock = $this->getMockBuilder(\Magento\Framework\View\Page\Title::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->sessionMock = $this->getMockBuilder(\Magento\Backend\Model\Session::class)
            ->disableOriginalConstructor()
            ->setMethods(['unsCustomerData', 'unsCustomerFormData'])
            ->getMock();

        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->context = $objectManager->getObject(
            \Magento\Backend\App\Action\Context::class,
            [
                'request' => $this->requestMock,
                'session' => $this->sessionMock
            ]
        );
        $this->indexController = $objectManager->getObject(
            \Magento\Customer\Controller\Adminhtml\Index\Index::class,
            [
                'context' => $this->context,
                'resultForwardFactory' => $this->resultForwardFactoryMock,
                'resultPageFactory' => $this->resultPageFactoryMock
            ]
        );
    }

    /**
     * @covers \Magento\Customer\Controller\Adminhtml\Index\Index::execute
     */
    public function testExecute()
    {
        $this->prepareExecute();

        $this->resultPageFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->resultPageMock);
        $this->resultPageMock->expects($this->once())
            ->method('setActiveMenu')
            ->with('Magento_Customer::customer_manage');
        $this->resultPageMock->expects($this->once())
            ->method('getConfig')
            ->willReturn($this->pageConfigMock);
        $this->pageConfigMock->expects($this->once())
            ->method('getTitle')
            ->willReturn($this->pageTitleMock);
        $this->pageTitleMock->expects($this->once())
            ->method('prepend')
            ->with('Customers');
        $this->resultPageMock->expects($this->atLeastOnce())
            ->method('addBreadcrumb')
            ->withConsecutive(
                ['Customers', 'Customers'],
                ['Manage Customers', 'Manage Customers']
            );
        $this->sessionMock->expects($this->once())
            ->method('unsCustomerData');
        $this->sessionMock->expects($this->once())
            ->method('unsCustomerFormData');

        $this->assertInstanceOf(
            \Magento\Framework\View\Result\Page::class,
            $this->indexController->execute()
        );
    }

    /**
     * @covers \Magento\Customer\Controller\Adminhtml\Index\Index::execute
     */
    public function testExecuteAjax()
    {
        $this->prepareExecute(true);

        $this->resultForwardFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->resultForwardMock);
        $this->resultForwardMock->expects($this->once())
            ->method('forward')
            ->with('grid')
            ->willReturnSelf();

        $this->assertInstanceOf(
            \Magento\Backend\Model\View\Result\Forward::class,
            $this->indexController->execute()
        );
    }

    /**
     * @param bool $ajax
     */
    protected function prepareExecute($ajax = false)
    {
        $this->requestMock->expects($this->once())
            ->method('getQuery')
            ->with('ajax')
            ->willReturn($ajax);
    }
}
