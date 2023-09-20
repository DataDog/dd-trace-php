<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Controller\Adminhtml\Product;

use Magento\Backend\App\Action\Context;
use Magento\Catalog\Controller\Adminhtml\Product\Attribute;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Registry;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Controller\ResultFactory;

/**
 * Class AttributeTest
 */
class AttributeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ObjectManagerHelper
     */
    protected $objectManager;

    /**
     * @var Context|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $contextMock;

    /**
     * @var Registry|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $coreRegistryMock;

    /**
     * @var FrontendInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $attributeLabelCacheMock;

    /**
     * @var PageFactory|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $resultPageFactoryMock;

    /**
     * @var RequestInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $requestMock;

    /**
     * @var ResultFactory|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $resultFactoryMock;

    /**
     * @var ManagerInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private $messageManager;

    protected function setUp()
    {
        $this->objectManager = new ObjectManagerHelper($this);
        $this->contextMock = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->attributeLabelCacheMock = $this->getMockBuilder(FrontendInterface::class)
            ->getMockForAbstractClass();
        $this->coreRegistryMock = $this->getMockBuilder(Registry::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resultPageFactoryMock = $this->getMockBuilder(PageFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->requestMock = $this->getMockBuilder(RequestInterface::class)
            ->setMethods(['getPostValue', 'has'])
            ->getMockForAbstractClass();
        $this->resultFactoryMock = $this->getMockBuilder(ResultFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->messageManager = $this->getMockBuilder(ManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->contextMock->expects($this->any())
            ->method('getRequest')
            ->willReturn($this->requestMock);
        $this->contextMock->expects($this->any())
            ->method('getResultFactory')
            ->willReturn($this->resultFactoryMock);
        $this->contextMock->expects($this->once())
            ->method('getMessageManager')
            ->willReturn($this->messageManager);
    }

    /**
     * @return Attribute
     */
    protected function getModel()
    {
        return $this->objectManager->getObject(Attribute::class, [
            'context' => $this->contextMock,
            'attributeLabelCache' => $this->attributeLabelCacheMock,
            'coreRegistry' => $this->coreRegistryMock,
            'resultPageFactory' => $this->resultPageFactoryMock,
        ]);
    }

    public function testDispatch()
    {
        $this->markTestSkipped('Should be dispatched in parent');
    }
}
