<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Contact\Test\Unit\Controller\Index;

use Magento\Contact\Model\ConfigInterface;
use Magento\Contact\Model\MailInterface;
use Magento\Framework\Controller\Result\Redirect;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PostTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Contact\Controller\Index\Index
     */
    private $controller;

    /**
     * @var ConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $configMock;

    /**
     * @var \Magento\Framework\Controller\Result\RedirectFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $redirectResultFactoryMock;

    /**
     * @var Redirect|\PHPUnit\Framework\MockObject\MockObject
     */
    private $redirectResultMock;

    /**
     * @var \Magento\Framework\UrlInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $urlMock;

    /**
     * @var \Magento\Framework\App\Request\HttpRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private $requestStub;

    /**
     * @var \Magento\Framework\Message\ManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $messageManagerMock;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $storeManagerMock;

    /**
     * @var \Magento\Framework\App\Request\DataPersistorInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $dataPersistorMock;

    /**
     * @var MailInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $mailMock;

    /**
     * test setup
     */
    protected function setUp(): void
    {
        $this->mailMock = $this->getMockBuilder(MailInterface::class)->getMockForAbstractClass();
        $this->configMock = $this->getMockBuilder(ConfigInterface::class)->getMockForAbstractClass();
        $context = $this->createPartialMock(
            \Magento\Framework\App\Action\Context::class,
            ['getRequest', 'getResponse', 'getResultRedirectFactory', 'getUrl', 'getRedirect', 'getMessageManager']
        );
        $this->urlMock = $this->createMock(\Magento\Framework\UrlInterface::class);
        $this->messageManagerMock =
            $this->createMock(\Magento\Framework\Message\ManagerInterface::class);
        $this->requestStub = $this->createPartialMock(
            \Magento\Framework\App\Request\Http::class,
            ['getPostValue', 'getParams', 'getParam', 'isPost']
        );
        $this->redirectResultMock = $this->createMock(\Magento\Framework\Controller\Result\Redirect::class);
        $this->redirectResultMock->method('setPath')->willReturnSelf();
        $this->redirectResultFactoryMock = $this->createPartialMock(
            \Magento\Framework\Controller\Result\RedirectFactory::class,
            ['create']
        );
        $this->redirectResultFactoryMock
            ->method('create')
            ->willReturn($this->redirectResultMock);
        $this->storeManagerMock = $this->createMock(\Magento\Store\Model\StoreManagerInterface::class);
        $this->dataPersistorMock = $this->getMockBuilder(\Magento\Framework\App\Request\DataPersistorInterface::class)
            ->getMockForAbstractClass();
        $context->expects($this->any())
            ->method('getRequest')
            ->willReturn($this->requestStub);

        $context->expects($this->any())
            ->method('getResponse')
            ->willReturn($this->createMock(\Magento\Framework\App\ResponseInterface::class));

        $context->expects($this->any())
            ->method('getMessageManager')
            ->willReturn($this->messageManagerMock);

        $context->expects($this->any())
            ->method('getUrl')
            ->willReturn($this->urlMock);

        $context->expects($this->once())
            ->method('getResultRedirectFactory')
            ->willReturn($this->redirectResultFactoryMock);

        $this->controller = new \Magento\Contact\Controller\Index\Post(
            $context,
            $this->configMock,
            $this->mailMock,
            $this->dataPersistorMock
        );
    }

    /**
     * testExecuteEmptyPost
     */
    public function testExecuteEmptyPost()
    {
        $this->stubRequestPostData([]);
        $this->assertSame($this->redirectResultMock, $this->controller->execute());
    }

    /**
     * @param array $postData
     * @param bool $exceptionExpected
     * @dataProvider postDataProvider
     */
    public function testExecutePostValidation($postData, $exceptionExpected)
    {
        $this->stubRequestPostData($postData);

        if ($exceptionExpected) {
            $this->messageManagerMock->expects($this->once())
                ->method('addErrorMessage');
            $this->dataPersistorMock->expects($this->once())
                ->method('set')
                ->with('contact_us', $postData);
        }

        $this->controller->execute();
    }

    /**
     * @return array
     */
    public function postDataProvider()
    {
        return [
            [['name' => null, 'comment' => null, 'email' => '', 'hideit' => 'no'], true],
            [['name' => 'test', 'comment' => '', 'email' => '', 'hideit' => 'no'], true],
            [['name' => '', 'comment' => 'test', 'email' => '', 'hideit' => 'no'], true],
            [['name' => '', 'comment' => '', 'email' => 'test', 'hideit' => 'no'], true],
            [['name' => '', 'comment' => '', 'email' => '', 'hideit' => 'no'], true],
            [['name' => 'Name', 'comment' => 'Name', 'email' => 'invalidmail', 'hideit' => 'no'], true],
        ];
    }

    /**
     * testExecuteValidPost
     */
    public function testExecuteValidPost()
    {
        $post = ['name' => 'Name', 'comment' => 'Comment', 'email' => 'valid@mail.com', 'hideit' => null];

        $this->dataPersistorMock->expects($this->once())
            ->method('clear')
            ->with('contact_us');

        $this->stubRequestPostData($post);

        $this->controller->execute();
    }

    /**
     * @param array $post
     */
    private function stubRequestPostData($post)
    {
        $this->requestStub
             ->expects($this->once())
             ->method('isPost')
             ->willReturn(!empty($post));
        $this->requestStub->method('getPostValue')->willReturn($post);
        $this->requestStub->method('getParams')->willReturn($post);
        $this->requestStub->method('getParam')->willReturnCallback(
            function ($key) use ($post) {
                return $post[$key];
            }
        );
    }
}
