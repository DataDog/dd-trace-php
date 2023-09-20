<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Theme\Test\Unit\Block\Html;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

class TitleTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ObjectManagerHelper
     */
    protected $objectManagerHelper;

    /**
     * @var \Magento\Framework\View\Page\Config|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $pageConfigMock;

    /**
     * @var \Magento\Framework\View\Page\Title|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $pageTitleMock;

    /**
     * @var \Magento\Theme\Block\Html\Title
     */
    protected $htmlTitle;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->pageConfigMock = $this->createMock(\Magento\Framework\View\Page\Config::class);
        $this->pageTitleMock = $this->createMock(\Magento\Framework\View\Page\Title::class);

        $context = $this->objectManagerHelper->getObject(
            \Magento\Framework\View\Element\Template\Context::class,
            ['pageConfig' => $this->pageConfigMock]
        );

        $this->htmlTitle = $this->objectManagerHelper->getObject(
            \Magento\Theme\Block\Html\Title::class,
            ['context' => $context]
        );
    }

    /**
     * @return void
     */
    public function testGetPageTitleWithSetPageTitle()
    {
        $title = 'some title';

        $this->htmlTitle->setPageTitle($title);
        $this->pageConfigMock->expects($this->never())
            ->method('getTitle');

        $this->assertEquals($title, $this->htmlTitle->getPageTitle());
    }

    /**
     * @return void
     */
    public function testGetPageTitle()
    {
        $title = 'some title';

        $this->pageTitleMock->expects($this->once())
            ->method('getShort')
            ->willReturn($title);
        $this->pageConfigMock->expects($this->once())
            ->method('getTitle')
            ->willReturn($this->pageTitleMock);

        $this->assertEquals($title, $this->htmlTitle->getPageTitle());
    }

    /**
     * @return void
     */
    public function testGetPageHeadingWithSetPageTitle()
    {
        $title = 'some title';

        $this->htmlTitle->setPageTitle($title);
        $this->pageConfigMock->expects($this->never())
            ->method('getTitle');

        $this->assertEquals($title, $this->htmlTitle->getPageHeading());
    }

    /**
     * @return void
     */
    public function testGetPageHeading()
    {
        $title = 'some title';

        $this->pageTitleMock->expects($this->once())
            ->method('getShortHeading')
            ->willReturn($title);
        $this->pageConfigMock->expects($this->once())
            ->method('getTitle')
            ->willReturn($this->pageTitleMock);

        $this->assertEquals($title, $this->htmlTitle->getPageHeading());
    }
}
