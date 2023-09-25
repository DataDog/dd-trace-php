<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Search\Test\Unit\Controller\Adminhtml\Ajax;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Framework\Controller\ResultFactory;

class SuggestTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Search\Controller\Ajax\Suggest */
    private $controller;

    /** @var ObjectManagerHelper */
    private $objectManagerHelper;

    /** @var \Magento\Framework\App\RequestInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $request;

    /** @var \Magento\Framework\UrlInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $url;

    /** @var \Magento\Backend\App\Action\Context|\PHPUnit\Framework\MockObject\MockObject */
    private $context;

    /** @var \Magento\Search\Model\AutocompleteInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $autocomplete;

    /**
     * @var \Magento\Framework\Controller\ResultFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultFactoryMock;

    /**
     * @var \Magento\Backend\Model\View\Result\Redirect|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultRedirectMock;

    /**
     * @var \Magento\Framework\Controller\Result\Json|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultJsonMock;

    protected function setUp(): void
    {
        $this->autocomplete = $this->getMockBuilder(\Magento\Search\Model\AutocompleteInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getItems'])
            ->getMockForAbstractClass();
        $this->request = $this->getMockBuilder(\Magento\Framework\App\RequestInterface::class)
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMockForAbstractClass();
        $this->url = $this->getMockBuilder(\Magento\Framework\UrlInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getBaseUrl'])
            ->getMockForAbstractClass();
        $this->resultFactoryMock = $this->getMockBuilder(\Magento\Framework\Controller\ResultFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resultRedirectMock = $this->getMockBuilder(\Magento\Backend\Model\View\Result\Redirect::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resultJsonMock = $this->getMockBuilder(\Magento\Framework\Controller\Result\Json::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->context = $this->getMockBuilder(\Magento\Framework\App\Action\Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->context->expects($this->atLeastOnce())
            ->method('getRequest')
            ->willReturn($this->request);
        $this->context->expects($this->any())
            ->method('getUrl')
            ->willReturn($this->url);
        $this->context->expects($this->any())
            ->method('getResultFactory')
            ->willReturn($this->resultFactoryMock);
        $this->resultFactoryMock->expects($this->any())
            ->method('create')
            ->willReturnMap(
                [
                    [ResultFactory::TYPE_REDIRECT, [], $this->resultRedirectMock],
                    [ResultFactory::TYPE_JSON, [], $this->resultJsonMock]
                ]
            );

        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->controller = $this->objectManagerHelper->getObject(
            \Magento\Search\Controller\Ajax\Suggest::class,
            [
                'context' => $this->context,
                'autocomplete' => $this->autocomplete
            ]
        );
    }

    public function testExecute()
    {
        $searchString = "simple";
        $firstItemMock =  $this->getMockBuilder(\Magento\Search\Model\Autocomplete\Item::class)
            ->disableOriginalConstructor()
            ->setMockClassName('FirstItem')
            ->setMethods(['toArray'])
            ->getMock();
        $secondItemMock =  $this->getMockBuilder(\Magento\Search\Model\Autocomplete\Item::class)
            ->disableOriginalConstructor()
            ->setMockClassName('SecondItem')
            ->setMethods(['toArray'])
            ->getMock();

        $this->request->expects($this->once())
            ->method('getParam')
            ->with('q')
            ->willReturn($searchString);

        $this->autocomplete->expects($this->once())
            ->method('getItems')
            ->willReturn([$firstItemMock, $secondItemMock]);

        $this->resultJsonMock->expects($this->once())
            ->method('setData')
            ->willReturnSelf();

        $this->assertSame($this->resultJsonMock, $this->controller->execute());
    }

    public function testExecuteEmptyQuery()
    {
        $url = 'some url';
        $searchString = '';

        $this->request->expects($this->once())
            ->method('getParam')
            ->with('q')
            ->willReturn($searchString);
        $this->url->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn($url);
        $this->resultRedirectMock->expects($this->once())
            ->method('setUrl')
            ->with($url)
            ->willReturnSelf();

        $this->assertSame($this->resultRedirectMock, $this->controller->execute());
    }
}
