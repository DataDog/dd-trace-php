<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Reports\Test\Unit\Controller\Adminhtml\Report;

/**
 * @SuppressWarnings(PHPMD.NumberOfChildren)
 */
abstract class AbstractControllerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Backend\App\Action\Context|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $contextMock;

    /**
     * @var \Magento\Framework\App\Response\Http\FileFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $fileFactoryMock;

    /**
     * @var \Magento\Framework\App\RequestInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $requestMock;

    /**
     * @var \Magento\Framework\App\ViewInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $viewMock;

    /**
     * @var \Magento\Framework\View\LayoutInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $layoutMock;

    /**
     * @var \Magento\Framework\View\Element\BlockInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $breadcrumbsBlockMock;

    /**
     * @var \Magento\Framework\View\Element\BlockInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $menuBlockMock;

    /**
     * @var \Magento\Framework\View\Element\BlockInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $switcherBlockMock;

    /**
     * @var \Magento\Backend\Model\Menu|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $menuModelMock;

    /**
     * @var \Magento\Framework\View\Element\AbstractBlock|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $abstractBlockMock;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        $this->requestMock = $this->getMockForAbstractClassBuilder(
            \Magento\Framework\App\RequestInterface::class,
            ['isDispatched', 'initForward', 'setDispatched', 'isForwarded']
        );
        $this->breadcrumbsBlockMock = $this->getMockForAbstractClassBuilder(
            \Magento\Framework\View\Element\BlockInterface::class,
            ['addLink']
        );
        $this->menuBlockMock = $this->getMockForAbstractClassBuilder(
            \Magento\Framework\View\Element\BlockInterface::class,
            ['setActive', 'getMenuModel']
        );
        $this->viewMock = $this->getMockForAbstractClassBuilder(
            \Magento\Framework\App\ViewInterface::class
        );

        $this->layoutMock = $this->getMockBuilder(\Magento\Framework\View\LayoutInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->switcherBlockMock = $this->getMockBuilder(\Magento\Framework\View\Element\BlockInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->contextMock = $this->getMockBuilder(\Magento\Backend\App\Action\Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->fileFactoryMock = $this->getMockBuilder(\Magento\Framework\App\Response\Http\FileFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->menuModelMock = $this->getMockBuilder(\Magento\Backend\Model\Menu::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->abstractBlockMock = $this->getMockBuilder(\Magento\Framework\View\Element\AbstractBlock::class)
            ->setMethods(['getCsvFile', 'getExcelFile', 'setSaveParametersInSession', 'getCsv', 'getExcel'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->menuModelMock->expects($this->any())->method('getParentItems')->willReturn([]);
        $this->menuBlockMock->expects($this->any())->method('getMenuModel')->willReturn($this->menuModelMock);
        $this->viewMock->expects($this->any())->method('getLayout')->willReturn($this->layoutMock);
        $this->contextMock->expects($this->any())->method('getRequest')->willReturn($this->requestMock);
        $this->contextMock->expects($this->any())->method('getView')->willReturn($this->viewMock);

        $this->layoutMock->expects($this->any())->method('getBlock')->willReturnMap(
            
                [
                    ['breadcrumbs', $this->breadcrumbsBlockMock],
                    ['menu', $this->menuBlockMock],
                    ['store_switcher', $this->switcherBlockMock]
                ]
            
        );
        $this->layoutMock->expects($this->any())->method('getChildBlock')->willReturn($this->abstractBlockMock);
    }

    /**
     * Custom mock for abstract class
     * @param string $className
     * @param array $mockedMethods
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    protected function getMockForAbstractClassBuilder($className, $mockedMethods = [])
    {
        return $this->getMockForAbstractClass($className, [], '', false, false, true, $mockedMethods);
    }
}
