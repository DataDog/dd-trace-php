<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Cms\Test\Unit\Controller\Adminhtml\Page;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Cms\Controller\Adminhtml\Page\Edit;
use Magento\Cms\Model\Page;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class EditTest extends TestCase
{
    /**
     * @var Edit
     */
    protected $editController;

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var Context|MockObject
     */
    protected $contextMock;

    /**
     * @var RedirectFactory|MockObject
     */
    protected $resultRedirectFactoryMock;

    /**
     * @var Redirect|MockObject
     */
    protected $resultRedirectMock;

    /**
     * @var ManagerInterface|MockObject
     */
    protected $messageManagerMock;

    /**
     * @var RequestInterface|MockObject
     */
    protected $requestMock;

    /**
     * @var \Magento\Cms\Model\Page|MockObject
     */
    protected $pageMock;

    /**
     * @var \Magento\Framework\ObjectManager\ObjectManager|MockObject
     */
    protected $objectManagerMock;

    /**
     * @var Registry|MockObject
     */
    protected $coreRegistryMock;

    /**
     * @var PageFactory|MockObject
     */
    protected $resultPageFactoryMock;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->messageManagerMock = $this->getMockForAbstractClass(ManagerInterface::class);
        $this->coreRegistryMock = $this->createMock(Registry::class);

        $this->pageMock = $this->getMockBuilder(Page::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->objectManagerMock = $this->getMockBuilder(\Magento\Framework\ObjectManager\ObjectManager::class)
            ->onlyMethods(['create', 'get'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->objectManagerMock->expects($this->once())
            ->method('create')
            ->with(Page::class)
            ->willReturn($this->pageMock);

        $this->resultRedirectMock = $this->getMockBuilder(Redirect::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->resultRedirectFactoryMock = $this->getMockBuilder(
            RedirectFactory::class
        )->disableOriginalConstructor()
            ->getMock();

        $this->resultPageFactoryMock = $this->createMock(PageFactory::class);

        $this->requestMock = $this->getMockForAbstractClass(
            RequestInterface::class,
            [],
            '',
            false,
            true,
            true,
            []
        );

        $this->contextMock = $this->createMock(Context::class);
        $this->contextMock->expects($this->once())->method('getRequest')->willReturn($this->requestMock);
        $this->contextMock->expects($this->once())->method('getObjectManager')->willReturn($this->objectManagerMock);
        $this->contextMock->expects($this->once())->method('getMessageManager')->willReturn($this->messageManagerMock);
        $this->contextMock->expects($this->once())
            ->method('getResultRedirectFactory')
            ->willReturn($this->resultRedirectFactoryMock);

        $this->editController = $this->objectManager->getObject(
            Edit::class,
            [
                'context' => $this->contextMock,
                'resultPageFactory' => $this->resultPageFactoryMock,
                'registry' => $this->coreRegistryMock
            ]
        );
    }

    /**
     * @return void
     */
    public function testEditActionPageNoExists(): void
    {
        $pageId = 1;

        $this->requestMock->expects($this->once())
            ->method('getParam')
            ->with('page_id')
            ->willReturn($pageId);

        $this->pageMock->expects($this->once())
            ->method('load')
            ->with($pageId);
        $this->pageMock->expects($this->once())
            ->method('getId')
            ->willReturn(null);

        $this->messageManagerMock->expects($this->once())
            ->method('addErrorMessage')
            ->with(__('This page no longer exists.'));

        $this->resultRedirectFactoryMock->expects($this->atLeastOnce())
            ->method('create')
            ->willReturn($this->resultRedirectMock);

        $this->resultRedirectMock->expects($this->once())
            ->method('setPath')
            ->with('*/*/')
            ->willReturnSelf();

        $this->assertSame($this->resultRedirectMock, $this->editController->execute());
    }

    /**
     * @param int|null $pageId
     * @param string $label
     * @param string $title
     *
     * @return void
     * @dataProvider editActionData
     */
    public function testEditAction(?int $pageId, string $label, string $title): void
    {
        $this->requestMock->expects($this->once())
            ->method('getParam')
            ->with('page_id')
            ->willReturn($pageId);

        $this->pageMock->expects($this->any())
            ->method('load')
            ->with($pageId);
        $this->pageMock->expects($this->any())
            ->method('getId')
            ->willReturn($pageId);
        $this->pageMock->expects($this->any())
            ->method('getTitle')
            ->willReturn('Test title');

        $this->coreRegistryMock->expects($this->once())
            ->method('register')
            ->with('cms_page', $this->pageMock);

        $resultPageMock = $this->createMock(\Magento\Backend\Model\View\Result\Page::class);

        $this->resultPageFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($resultPageMock);

        $titleMock = $this->createMock(Title::class);
        $titleMock
            ->method('prepend')
            ->withConsecutive([__('Pages')], [$this->getTitle()]);
        $pageConfigMock = $this->createMock(Config::class);
        $pageConfigMock->expects($this->exactly(2))->method('getTitle')->willReturn($titleMock);

        $resultPageMock->expects($this->once())
            ->method('setActiveMenu')
            ->willReturnSelf();
        $resultPageMock->expects($this->any())
            ->method('addBreadcrumb')
            ->willReturnSelf();
        $resultPageMock
            ->method('addBreadcrumb')
            ->withConsecutive([], [], [], [__($label), __($title)])
            ->willReturnOnConsecutiveCalls(null, null, null, $resultPageMock);
        $resultPageMock->expects($this->exactly(2))
            ->method('getConfig')
            ->willReturn($pageConfigMock);

        $this->assertSame($resultPageMock, $this->editController->execute());
    }

    /**
     * @return Phrase|string
     */
    protected function getTitle()
    {
        return $this->pageMock->getId() ? $this->pageMock->getTitle() : __('New Page');
    }

    /**
     * @return array
     */
    public function editActionData(): array
    {
        return [
            [null, 'New Page', 'New Page'],
            [2, 'Edit Page', 'Edit Page']
        ];
    }
}
