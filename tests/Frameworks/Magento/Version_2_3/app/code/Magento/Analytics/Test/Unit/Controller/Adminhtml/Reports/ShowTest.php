<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Analytics\Test\Unit\Controller\Adminhtml\Reports;

use Magento\Analytics\Controller\Adminhtml\Reports\Show;
use Magento\Analytics\Model\Exception\State\SubscriptionUpdateException;
use Magento\Analytics\Model\ReportUrlProvider;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ShowTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ReportUrlProvider|\PHPUnit\Framework\MockObject\MockObject
     */
    private $reportUrlProviderMock;

    /**
     * @var Redirect|\PHPUnit\Framework\MockObject\MockObject
     */
    private $redirectMock;

    /**
     * @var ResultFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $resultFactoryMock;

    /**
     * @var ManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $messageManagerMock;

    /**
     * @var ObjectManagerHelper
     */
    private $objectManagerHelper;

    /**
     * @var Show
     */
    private $showController;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->reportUrlProviderMock = $this->getMockBuilder(ReportUrlProvider::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->resultFactoryMock = $this->getMockBuilder(ResultFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->redirectMock = $this->getMockBuilder(Redirect::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->messageManagerMock = $this->getMockBuilder(ManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->objectManagerHelper = new ObjectManagerHelper($this);

        $this->showController = $this->objectManagerHelper->getObject(
            Show::class,
            [
                'reportUrlProvider' => $this->reportUrlProviderMock,
                'resultFactory' => $this->resultFactoryMock,
                'messageManager' => $this->messageManagerMock,
            ]
        );
    }

    /**
     * @return void
     */
    public function testExecute()
    {
        $otpUrl = 'http://example.com?otp=15vbjcfdvd15645';

        $this->resultFactoryMock
            ->expects($this->once())
            ->method('create')
            ->with(ResultFactory::TYPE_REDIRECT)
            ->willReturn($this->redirectMock);
        $this->reportUrlProviderMock
            ->expects($this->once())
            ->method('getUrl')
            ->with()
            ->willReturn($otpUrl);
        $this->redirectMock
            ->expects($this->once())
            ->method('setUrl')
            ->with($otpUrl)
            ->willReturnSelf();
        $this->assertSame($this->redirectMock, $this->showController->execute());
    }

    /**
     * @dataProvider executeWithExceptionDataProvider
     *
     * @param \Exception $exception
     */
    public function testExecuteWithException(\Exception $exception)
    {

        $this->resultFactoryMock
            ->expects($this->once())
            ->method('create')
            ->with(ResultFactory::TYPE_REDIRECT)
            ->willReturn($this->redirectMock);
        $this->reportUrlProviderMock
            ->expects($this->once())
            ->method('getUrl')
            ->with()
            ->willThrowException($exception);
        if ($exception instanceof LocalizedException) {
            $message = $exception->getMessage();
        } else {
            $message = __('Sorry, there has been an error processing your request. Please try again later.');
        }
        $this->messageManagerMock
            ->expects($this->once())
            ->method('addExceptionMessage')
            ->with($exception, $message)
            ->willReturnSelf();
        $this->redirectMock
            ->expects($this->once())
            ->method('setPath')
            ->with('adminhtml')
            ->willReturnSelf();
        $this->assertSame($this->redirectMock, $this->showController->execute());
    }

    /**
     * @return array
     */
    public function executeWithExceptionDataProvider()
    {
        return [
            'ExecuteWithLocalizedException' => [new LocalizedException(__('TestMessage'))],
            'ExecuteWithException' => [new \Exception('TestMessage')],
        ];
    }

    /**
     * @return void
     */
    public function testExecuteWithSubscriptionUpdateException()
    {
        $exception = new SubscriptionUpdateException(__('TestMessage'));
        $this->resultFactoryMock
            ->expects($this->once())
            ->method('create')
            ->with(ResultFactory::TYPE_REDIRECT)
            ->willReturn($this->redirectMock);
        $this->reportUrlProviderMock
            ->expects($this->once())
            ->method('getUrl')
            ->with()
            ->willThrowException($exception);
        $this->messageManagerMock
            ->expects($this->once())
            ->method('addNoticeMessage')
            ->with($exception->getMessage())
            ->willReturnSelf();
        $this->redirectMock
            ->expects($this->once())
            ->method('setPath')
            ->with('adminhtml')
            ->willReturnSelf();
        $this->assertSame($this->redirectMock, $this->showController->execute());
    }
}
