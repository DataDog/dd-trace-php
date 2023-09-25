<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Marketplace\Test\Unit\Controller\Index;

class IndexTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject| \Magento\Marketplace\Controller\Adminhtml\Index\Index
     */
    private $indexControllerMock;

    protected function setUp(): void
    {
        $this->indexControllerMock = $this->getControllerIndexMock(['getResultPageFactory']);
    }

    /**
     * @covers \Magento\Marketplace\Controller\Adminhtml\Index\Index::execute
     */
    public function testExecute()
    {
        $pageMock = $this->getPageMock(['setActiveMenu', 'addBreadcrumb', 'getConfig']);
        $pageMock->expects($this->once())
            ->method('setActiveMenu');
        $pageMock->expects($this->once())
            ->method('addBreadcrumb');

        $resultPageFactoryMock = $this->getResultPageFactoryMock(['create']);

        $resultPageFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($pageMock);

        $this->indexControllerMock->expects($this->once())
            ->method('getResultPageFactory')
            ->willReturn($resultPageFactoryMock);

        $titleMock = $this->getTitleMock(['prepend']);
        $titleMock->expects($this->once())
            ->method('prepend');
        $configMock =  $this->getConfigMock(['getTitle']);
        $configMock->expects($this->once())
            ->method('getTitle')
            ->willReturn($titleMock);
        $pageMock->expects($this->once())
            ->method('getConfig')
            ->willReturn($configMock);

        $this->indexControllerMock->execute();
    }

    /**
     * Gets index controller mock
     *
     * @return \PHPUnit\Framework\MockObject\MockObject|\Magento\Marketplace\Controller\Adminhtml\Index\Index
     */
    public function getControllerIndexMock($methods = null)
    {
        return $this->createPartialMock(\Magento\Marketplace\Controller\Adminhtml\Index\Index::class, $methods);
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\View\Result\PageFactory
     */
    public function getResultPageFactoryMock($methods = null)
    {
        return $this->createPartialMock(\Magento\Framework\View\Result\PageFactory::class, $methods, []);
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\View\Page\Config
     */
    public function getConfigMock($methods = null)
    {
        return $this->createPartialMock(\Magento\Framework\View\Page\Config::class, $methods, []);
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\View\Page\Title
     */
    public function getTitleMock($methods = null)
    {
        return $this->createPartialMock(\Magento\Framework\View\Page\Title::class, $methods, []);
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\View\Page\Title
     */
    public function getPageMock($methods = null)
    {
        return $this->createPartialMock(\Magento\Framework\View\Result\Page::class, $methods, []);
    }
}
