<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Theme\Test\Unit\Controller\Adminhtml\System\Design\Theme;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Theme\Controller\Adminhtml\System\Design\Theme\DownloadCustomCss;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DownloadCustomCssTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Registry|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $registry;

    /**
     * @var \Magento\Framework\App\Response\Http\FileFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $fileFactory;

    /**
     * @var \Magento\Framework\View\Asset\Repository|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $repository;

    /**
     * @var \Magento\Framework\Filesystem|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $filesystem;

    /**
     * @var \Magento\Framework\ObjectManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectManager;

    /**
     * @var \Magento\Framework\Message\ManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $messageManager;

    /**
     * @var \Magento\Framework\App\Response\RedirectInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $redirect;

    /**
     * @var \Magento\Framework\App\RequestInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $request;

    /**
     * @var \Magento\Framework\App\ResponseInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $response;

    /**
     * @var \Magento\Framework\Controller\ResultFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultFactory;

    /**
     * @var DownloadCustomCss
     */
    protected $controller;

    protected function setUp(): void
    {
        $context = $this->getMockBuilder(\Magento\Backend\App\Action\Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->request = $this->getMockBuilder(\Magento\Framework\App\RequestInterface::class)->getMock();
        $this->redirect = $this->getMockBuilder(\Magento\Framework\App\Response\RedirectInterface::class)->getMock();
        $this->response = $this->getMockBuilder(\Magento\Framework\App\ResponseInterface::class)
            ->setMethods(['sendResponse', 'setRedirect'])
            ->getMock();
        $this->objectManager = $this->getMockBuilder(\Magento\Framework\ObjectManagerInterface::class)->getMock();
        $this->messageManager = $this->getMockBuilder(\Magento\Framework\Message\ManagerInterface::class)->getMock();
        $this->resultFactory = $this->getMockBuilder(\Magento\Framework\Controller\ResultFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $context->expects($this->any())
            ->method('getRequest')
            ->willReturn($this->request);
        $context->expects($this->any())
            ->method('getRedirect')
            ->willReturn($this->redirect);
        $context->expects($this->any())
            ->method('getResponse')
            ->willReturn($this->response);
        $context->expects($this->any())
            ->method('getObjectManager')
            ->willReturn($this->objectManager);
        $context->expects($this->any())
            ->method('getMessageManager')
            ->willReturn($this->messageManager);
        $context->expects($this->any())
            ->method('getResultFactory')
            ->willReturn($this->resultFactory);

        $this->registry = $this->getMockBuilder(
            \Magento\Framework\Registry::class
        )->disableOriginalConstructor()->getMock();
        $this->fileFactory = $this->getMockBuilder(\Magento\Framework\App\Response\Http\FileFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->repository = $this->getMockBuilder(\Magento\Framework\View\Asset\Repository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->filesystem = $this->getMockBuilder(\Magento\Framework\Filesystem::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var \Magento\Backend\App\Action\Context $context */
        $this->controller = new DownloadCustomCss(
            $context,
            $this->registry,
            $this->fileFactory,
            $this->repository,
            $this->filesystem
        );
    }

    public function testExecute()
    {
        $themeId = 1;
        $fileName = 'file.ext';
        $fullPath = 'path/to/file';

        $file = $this->getMockBuilder(\Magento\Framework\View\Design\Theme\FileInterface::class)->getMock();
        $customization = $this->getMockBuilder(\Magento\Framework\View\Design\Theme\Customization::class)
            ->disableOriginalConstructor()
            ->getMock();
        $theme = $this->getMockBuilder(\Magento\Framework\View\Design\ThemeInterface::class)
            ->setMethods(['getCustomization'])
            ->getMockForAbstractClass();
        $file->expects($this->once())
            ->method('getContent')
            ->willReturn('some_content');
        $file->expects($this->once())
            ->method('getFilename')
            ->willReturn($fileName);
        $file->expects($this->once())
            ->method('getFullPath')
            ->willReturn($fullPath);
        $theme->expects($this->once())
            ->method('getCustomization')
            ->willReturn($customization);
        $customization->expects($this->once())
            ->method('getFilesByType')
            ->with(\Magento\Theme\Model\Theme\Customization\File\CustomCss::TYPE)
            ->willReturn([$file]);
        $this->request->expects($this->any())
            ->method('getParam')
            ->with('theme_id')
            ->willReturn($themeId);
        $themeFactory = $this->getMockBuilder(\Magento\Framework\View\Design\Theme\FlyweightFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->objectManager->expects($this->any())
            ->method('create')
            ->with(\Magento\Framework\View\Design\Theme\FlyweightFactory::class)
            ->willReturn($themeFactory);
        $themeFactory->expects($this->once())
            ->method('create')
            ->with($themeId)
            ->willReturn($theme);
        $this->fileFactory->expects($this->once())
            ->method('create')
            ->with($fileName, ['type' => 'filename', 'value' => $fullPath], DirectoryList::ROOT)
            ->willReturn($this->getMockBuilder(\Magento\Framework\App\ResponseInterface::class)->getMock());

        $this->assertInstanceOf(\Magento\Framework\App\ResponseInterface::class, $this->controller->execute());
    }

    public function testExecuteInvalidArgument()
    {
        $themeId = 1;
        $refererUrl = 'referer/url';

        $this->request->expects($this->any())
            ->method('getParam')
            ->with('theme_id')
            ->willReturn($themeId);
        $themeFactory = $this->getMockBuilder(\Magento\Framework\View\Design\Theme\FlyweightFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $logger = $this->getMockBuilder(\Psr\Log\LoggerInterface::class)->getMock();
        $this->objectManager->expects($this->any())
            ->method('get')
            ->with(\Psr\Log\LoggerInterface::class)
            ->willReturn($logger);
        $this->objectManager->expects($this->any())
            ->method('create')
            ->with(\Magento\Framework\View\Design\Theme\FlyweightFactory::class)
            ->willReturn($themeFactory);
        $themeFactory->expects($this->once())
            ->method('create')
            ->with($themeId)
            ->willReturn(null);
        $this->messageManager->expects($this->once())
            ->method('addException');
        $logger->expects($this->once())
            ->method('critical');
        $this->redirect->expects($this->once())
            ->method('getRefererUrl')
            ->willReturn($refererUrl);
        $this->response->expects($this->once())
            ->method('setRedirect')
            ->with($refererUrl);

        $this->controller->execute();
    }
}
