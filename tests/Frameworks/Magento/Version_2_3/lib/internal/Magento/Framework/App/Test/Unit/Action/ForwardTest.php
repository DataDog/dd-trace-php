<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\App\Test\Unit\Action;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * Test Forward
 *
 * getRequest,getResponse of AbstractAction class is also tested
 */
class ForwardTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\App\Action\Forward
     */
    protected $actionAbstract;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * @var \Magento\Framework\App\ResponseInterface
     */
    protected $response;

    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);
        $cookieMetadataFactoryMock = $this->getMockBuilder(
            \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory::class
        )->disableOriginalConstructor()->getMock();
        $cookieManagerMock = $this->createMock(\Magento\Framework\Stdlib\CookieManagerInterface::class);
        $contextMock = $this->getMockBuilder(\Magento\Framework\App\Http\Context::class)->disableOriginalConstructor()
            ->getMock();
        $this->response = $objectManager->getObject(
            \Magento\Framework\App\Response\Http::class,
            [
                'cookieManager' => $cookieManagerMock,
                'cookieMetadataFactory' => $cookieMetadataFactoryMock,
                'context' => $contextMock
            ]
        );

        $this->request = $this->getMockBuilder(\Magento\Framework\App\Request\Http::class)
            ->disableOriginalConstructor()->getMock();

        $this->actionAbstract = $objectManager->getObject(
            \Magento\Framework\App\Action\Forward::class,
            [
                'request' => $this->request,
                'response' => $this->response
            ]
        );
    }

    public function testDispatch()
    {
        $this->request->expects($this->once())->method('setDispatched')->with(false);
        $this->actionAbstract->dispatch($this->request);
    }

    /**
     * Test for getRequest method
     *
     * @test
     * @covers \Magento\Framework\App\Action\AbstractAction::getRequest
     */
    public function testGetRequest()
    {
        $this->assertSame($this->request, $this->actionAbstract->getRequest());
    }

    /**
     * Test for getResponse method
     *
     * @test
     * @covers \Magento\Framework\App\Action\AbstractAction::getResponse
     */
    public function testGetResponse()
    {
        $this->assertSame($this->response, $this->actionAbstract->getResponse());
    }

    /**
     * Test for getResponse med. Checks that response headers are set correctly
     *
     * @test
     * @covers \Magento\Framework\App\Action\AbstractAction::getResponse
     */
    public function testResponseHeaders()
    {
        $this->assertEmpty($this->actionAbstract->getResponse()->getHeaders());
    }
}
