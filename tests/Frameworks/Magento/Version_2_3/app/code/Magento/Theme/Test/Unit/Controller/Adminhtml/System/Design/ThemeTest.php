<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Theme\Test\Unit\Controller\Adminhtml\System\Design;

/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class ThemeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var string
     */
    protected $name = '';

    /**
     * @var \Magento\Theme\Controller\Adminhtml\System\Design\Theme
     */
    protected $_model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_objectManagerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_request;

    /**
     * @var \Magento\Framework\Event\ManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $eventManager;

    /**
     * @var \Magento\Framework\App\ViewInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $view;

    /** @var \Magento\Framework\Message\ManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $messageManager;

    /** @var \Magento\Framework\Message\ManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $resultFactory;

    /** @var \Magento\Framework\View\Asset\Repository|\PHPUnit\Framework\MockObject\MockObject */
    protected $assetRepo;

    /** @var \Magento\Framework\Filesystem|\PHPUnit\Framework\MockObject\MockObject */
    protected $appFileSystem;

    /** @var \Magento\Framework\App\Response\Http\FileFactory|\PHPUnit\Framework\MockObject\MockObject */
    protected $fileFactory;

    /** @var \Magento\Framework\App\Response\Http|\PHPUnit\Framework\MockObject\MockObject */
    protected $response;

    /** @var \Magento\Framework\App\Response\RedirectInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $redirect;

    /** @var \Magento\Backend\Model\Session|\PHPUnit\Framework\MockObject\MockObject */
    protected $session;

    /** @var \Magento\Framework\App\ActionFlag|\PHPUnit\Framework\MockObject\MockObject */
    protected $actionFlag;

    /** @var \Magento\Backend\Helper\Data|\PHPUnit\Framework\MockObject\MockObject */
    protected $backendHelper;

    /** @var \Magento\Framework\Registry|\PHPUnit\Framework\MockObject\MockObject */
    protected $coreRegistry;

    protected function setUp(): void
    {
        $this->_objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);

        $this->_request = $this->createMock(\Magento\Framework\App\Request\Http::class);
        $this->eventManager = $this->createMock(\Magento\Framework\Event\ManagerInterface::class);
        $this->view = $this->createMock(\Magento\Framework\App\ViewInterface::class);
        $this->messageManager = $this->getMockForAbstractClass(
            \Magento\Framework\Message\ManagerInterface::class,
            [],
            '',
            false
        );
        $this->resultFactory = $this->createMock(\Magento\Framework\Controller\ResultFactory::class);
        $this->assetRepo = $this->createMock(\Magento\Framework\View\Asset\Repository::class);
        $this->appFileSystem = $this->createMock(\Magento\Framework\Filesystem::class);
        $this->fileFactory = $this->createMock(\Magento\Framework\App\Response\Http\FileFactory::class);
        $this->response = $this->createMock(\Magento\Framework\App\Response\Http::class);
        $this->redirect = $this->getMockForAbstractClass(
            \Magento\Framework\App\Response\RedirectInterface::class,
            [],
            '',
            false
        );
        $this->session = $this->createPartialMock(
            \Magento\Backend\Model\Session::class,
            ['setIsUrlNotice', 'setThemeData', 'setThemeCustomCssData']
        );
        $this->actionFlag = $this->createMock(\Magento\Framework\App\ActionFlag::class);
        $this->backendHelper = $this->createMock(\Magento\Backend\Helper\Data::class);
        $this->coreRegistry = $this->createMock(\Magento\Framework\Registry::class);

        $helper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->_model = $helper->getObject(
            'Magento\Theme\Controller\Adminhtml\System\Design\Theme\\' . $this->name,
            [
                'request' => $this->_request,
                'objectManager' => $this->_objectManagerMock,
                'response' => $this->response,
                'eventManager' => $this->eventManager,
                'view' => $this->view,
                'messageManager' => $this->messageManager,
                'resultFactory' => $this->resultFactory,
                'assetRepo' => $this->assetRepo,
                'appFileSystem' => $this->appFileSystem,
                'fileFactory' => $this->fileFactory,
                'redirect' => $this->redirect,
                'session' => $this->session,
                'actionFlag' => $this->actionFlag,
                'helper' => $this->backendHelper,
                'coreRegistry' => $this->coreRegistry
            ]
        );
    }
}
