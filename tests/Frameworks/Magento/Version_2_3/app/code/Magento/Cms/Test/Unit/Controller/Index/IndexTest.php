<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Cms\Test\Unit\Controller\Index;

class IndexTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Cms\Controller\Index\Index
     */
    protected $controller;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $cmsHelperMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $requestMock;

    /**
     * @var \Magento\Framework\Controller\Result\ForwardFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $forwardFactoryMock;

    /**
     * @var \Magento\Framework\Controller\Result\Forward|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $forwardMock;

    /**
     * @var \Magento\Framework\View\Result\Page|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultPageMock;

    /**
     * @var string
     */
    protected $pageId = 'home';

    /**
     * Test setUp
     */
    protected function setUp(): void
    {
        $helper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $responseMock = $this->createMock(\Magento\Framework\App\Response\Http::class);
        $this->resultPageMock = $this->getMockBuilder(\Magento\Framework\View\Result\Page::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->forwardFactoryMock = $this->getMockBuilder(\Magento\Framework\Controller\Result\ForwardFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->forwardMock = $this->getMockBuilder(\Magento\Framework\Controller\Result\Forward::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->forwardFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($this->forwardMock);

        $scopeConfigMock = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $this->requestMock = $this->createMock(\Magento\Framework\App\Request\Http::class);
        $this->cmsHelperMock = $this->createMock(\Magento\Cms\Helper\Page::class);
        $valueMap = [
            [\Magento\Framework\App\Config\ScopeConfigInterface::class,
                $scopeConfigMock,
            ],
            [\Magento\Cms\Helper\Page::class, $this->cmsHelperMock],
        ];
        $objectManagerMock->expects($this->any())->method('get')->willReturnMap($valueMap);
        $scopeConfigMock->expects($this->once())
            ->method('getValue')
            ->with(
                \Magento\Cms\Helper\Page::XML_PATH_HOME_PAGE,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            )
            ->willReturn($this->pageId);
        $this->controller = $helper->getObject(
            \Magento\Cms\Controller\Index\Index::class,
            [
                'response' => $responseMock,
                'objectManager' => $objectManagerMock,
                'request' => $this->requestMock,
                'resultForwardFactory' => $this->forwardFactoryMock,
                'scopeConfig' => $scopeConfigMock,
                'page' => $this->cmsHelperMock
            ]
        );
    }

    /**
     * Controller test
     */
    public function testExecuteResultPage()
    {
        $this->cmsHelperMock->expects($this->once())
            ->method('prepareResultPage')
            ->with($this->controller, $this->pageId)
            ->willReturn($this->resultPageMock);
        $this->assertSame($this->resultPageMock, $this->controller->execute());
    }

    /**
     * Controller test
     */
    public function testExecuteResultForward()
    {
        $this->forwardMock->expects($this->once())
            ->method('forward')
            ->with('defaultIndex')
            ->willReturnSelf();
        $this->assertSame($this->forwardMock, $this->controller->execute());
    }
}
