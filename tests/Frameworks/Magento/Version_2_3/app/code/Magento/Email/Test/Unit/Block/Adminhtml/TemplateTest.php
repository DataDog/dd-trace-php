<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Email\Test\Unit\Block\Adminhtml;

/**
 * @covers Magento\Email\Block\Adminhtml\Template
 */
class TemplateTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Email\Block\Adminhtml\Template */
    protected $template;

    /** @var \Magento\Backend\Block\Template\Context */
    protected $context;

    /** @var \Magento\Framework\UrlInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $urlBuilderMock;

    /** @var \Magento\Backend\Block\Widget\Button\ItemFactory|\PHPUnit\Framework\MockObject\MockObject */
    protected $itemFactoryMock;

    /** @var \Magento\Backend\Block\Widget\Button\ButtonList */
    protected $buttonList;

    /** @var \Magento\Backend\Block\Widget\Button\Item|\PHPUnit\Framework\MockObject\MockObject */
    protected $buttonMock;

    /** @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager */
    protected $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->itemFactoryMock = $this->getMockBuilder(\Magento\Backend\Block\Widget\Button\ItemFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->buttonMock = $this->getMockBuilder(\Magento\Backend\Block\Widget\Button\Item::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->itemFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($this->buttonMock);
        $this->buttonList = $this->objectManager->getObject(
            \Magento\Backend\Block\Widget\Button\ButtonList::class,
            [ 'itemFactory' => $this->itemFactoryMock]
        );
        $this->urlBuilderMock = $this->getMockForAbstractClass(
            \Magento\Framework\UrlInterface::class,
            [],
            '',
            false,
            true,
            true,
            ['getUrl']
        );
        $this->context = $this->objectManager->getObject(
            \Magento\Backend\Block\Template\Context::class,
            [
                'urlBuilder' => $this->urlBuilderMock
            ]
        );
        $this->template = $this->objectManager->getObject(
            \Magento\Email\Block\Adminhtml\Template::class,
            [
                'context' => $this->context,
                'buttonList' => $this->buttonList
            ]
        );
    }

    public function testAddButton()
    {
        $this->template->addButton('1', ['title' => 'My Button']);
        $buttons = $this->buttonList->getItems()[0];
        $this->assertArrayHasKey('1', $buttons);
    }

    public function testUpdateButton()
    {
        $this->testAddButton();
        $this->buttonMock->expects($this->once())
            ->method('setData')
            ->with('title', 'Updated Button')
            ->willReturnSelf();
        $result = $this->template->updateButton('1', 'title', 'Updated Button');
        $this->assertSame($this->template, $result);
    }

    public function testRemoveButton()
    {
        $this->testAddButton();
        $this->template->removeButton('1');
        $buttons = $this->buttonList->getItems()[0];
        $this->assertNotContains('1', array_keys($buttons));
    }

    public function testGetCreateUrl()
    {
        $this->urlBuilderMock->expects($this->once())
            ->method('getUrl')
            ->with('adminhtml/*/new', []);
        $this->template->getCreateUrl();
    }

    public function testGetHeaderText()
    {
        $this->assertEquals('Transactional Emails', $this->template->getHeaderText());
    }

    public function testCanRender()
    {
        $this->buttonMock->expects($this->once())
            ->method('isDeleted')
            ->willReturn(false);
        $this->assertTrue($this->template->canRender($this->buttonMock));
    }
}
