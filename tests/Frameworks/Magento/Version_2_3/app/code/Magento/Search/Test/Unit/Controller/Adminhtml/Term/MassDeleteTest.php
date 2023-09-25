<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Search\Test\Unit\Controller\Adminhtml\Term;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Framework\Controller\ResultFactory;

class MassDeleteTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Framework\Message\ManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $messageManager;

    /** @var  \Magento\Framework\ObjectManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $objectManager;

    /** @var \Magento\Search\Controller\Adminhtml\Term\MassDelete */
    private $controller;

    /** @var ObjectManagerHelper */
    private $objectManagerHelper;

    /** @var \Magento\Backend\App\Action\Context|\PHPUnit\Framework\MockObject\MockObject */
    private $context;

    /** @var \Magento\Framework\View\Result\PageFactory|\PHPUnit\Framework\MockObject\MockObject */
    private $pageFactory;

    /** @var \Magento\Framework\App\RequestInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $request;

    /**
     * @var \Magento\Framework\Controller\ResultFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $resultFactoryMock;

    /**
     * @var \Magento\Backend\Model\View\Result\Redirect|\PHPUnit\Framework\MockObject\MockObject
     */
    private $resultRedirectMock;

    protected function setUp(): void
    {
        $this->request = $this->getMockBuilder(\Magento\Framework\App\RequestInterface::class)
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMockForAbstractClass();
        $this->objectManager = $this->getMockBuilder(\Magento\Framework\ObjectManagerInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMockForAbstractClass();
        $this->messageManager = $this->getMockBuilder(\Magento\Framework\Message\ManagerInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['addSuccessMessage', 'addErrorMessage'])
            ->getMockForAbstractClass();
        $this->pageFactory = $this->getMockBuilder(\Magento\Framework\View\Result\PageFactory::class)
            ->setMethods([])
            ->disableOriginalConstructor()
            ->getMock();
        $this->resultRedirectMock = $this->getMockBuilder(\Magento\Backend\Model\View\Result\Redirect::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resultFactoryMock = $this->getMockBuilder(\Magento\Framework\Controller\ResultFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resultFactoryMock->expects($this->any())
            ->method('create')
            ->with(ResultFactory::TYPE_REDIRECT, [])
            ->willReturn($this->resultRedirectMock);
        $this->context = $this->getMockBuilder(\Magento\Backend\App\Action\Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->context->expects($this->atLeastOnce())
            ->method('getRequest')
            ->willReturn($this->request);
        $this->context->expects($this->any())
            ->method('getObjectManager')
            ->willReturn($this->objectManager);
        $this->context->expects($this->any())
            ->method('getMessageManager')
            ->willReturn($this->messageManager);
        $this->context->expects($this->any())
            ->method('getResultFactory')
            ->willReturn($this->resultFactoryMock);

        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->controller = $this->objectManagerHelper->getObject(
            \Magento\Search\Controller\Adminhtml\Term\MassDelete::class,
            [
                'context' => $this->context,
                'resultPageFactory' => $this->pageFactory,
            ]
        );
    }

    public function testExecute()
    {
        $ids = [1, 2];
        $this->request->expects($this->once())
            ->method('getParam')
            ->with('search')
            ->willReturn($ids);

        $this->createQuery(0, 1);
        $this->createQuery(1, 2);
        $this->messageManager->expects($this->once())
            ->method('addSuccessMessage')
            ->willReturnSelf();
        $this->resultRedirectMock->expects($this->once())
            ->method('setPath')
            ->with('search/*/')
            ->willReturnSelf();

        $this->assertSame($this->resultRedirectMock, $this->controller->execute());
    }

    /**
     * @param $index
     * @param $id
     * @return \Magento\Search\Model\Query|\PHPUnit\Framework\MockObject\MockObject
     */
    private function createQuery($index, $id)
    {
        $query = $this->getMockBuilder(\Magento\Search\Model\Query::class)
            ->disableOriginalConstructor()
            ->setMethods(['load', 'delete'])
            ->getMock();
        $query->expects($this->at(0))
            ->method('delete')
            ->willReturnSelf();
        $query->expects($this->at(0))
            ->method('load')
            ->with($id)
            ->willReturnSelf();
        $this->objectManager->expects($this->at($index))
            ->method('create')
            ->with(\Magento\Search\Model\Query::class)
            ->willReturn($query);
        return $query;
    }
}
