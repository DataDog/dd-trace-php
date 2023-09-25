<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Tax\Test\Unit\Controller\Adminhtml\Tax;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class IgnoreTaxNotificationTest extends \PHPUnit\Framework\TestCase
{
    public function testExecute()
    {
        $objectManager = new ObjectManager($this);
        $cacheTypeList = $this->getMockBuilder(\Magento\Framework\App\Cache\TypeList::class)
            ->disableOriginalConstructor()
            ->setMethods(['cleanType'])
            ->getMock();
        $cacheTypeList->expects($this->once())
            ->method('cleanType')
            ->with('config')
            ->willReturn(null);

        $request = $this->getMockBuilder(\Magento\Framework\App\Request\Http::class)
            ->disableOriginalConstructor()
            ->setMethods(['getParam'])
            ->getMock();
        $request->expects($this->once())
            ->method('getParam')
            ->willReturn('tax');

        $resultRedirect = $this->getMockBuilder(\Magento\Backend\Model\View\Result\Redirect::class)
            ->disableOriginalConstructor()
            ->getMock();
        $resultRedirect->expects($this->once())
            ->method('setRefererUrl')
            ->willReturnSelf();

        $resultFactory = $this->getMockBuilder(\Magento\Framework\Controller\ResultFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $resultFactory->expects($this->once())
            ->method('create')
            ->with(\Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT)
            ->willReturn($resultRedirect);

        $config = $this->getMockBuilder(\Magento\Config\Model\ResourceModel\Config::class)
            ->disableOriginalConstructor()
            ->setMethods(['saveConfig'])
            ->getMock();
        $config->expects($this->once())
            ->method('saveConfig')
            ->with('tax/notification/ignore_tax', 1, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0)
            ->willReturn(null);

        $manager = $this->getMockBuilder(\Magento\Framework\ObjectManagerInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['get', 'create', 'configure'])
            ->getMock();
        $manager->expects($this->any())
            ->method('get')
            ->willReturn($config);

        $notification = $objectManager->getObject(
            \Magento\Tax\Controller\Adminhtml\Tax\IgnoreTaxNotification::class,
            [
                'objectManager' => $manager,
                'cacheTypeList' => $cacheTypeList,
                'request' => $request,
                'resultFactory' => $resultFactory
            ]
        );

        // No exception thrown
        $this->assertSame($resultRedirect, $notification->execute());
    }
}
