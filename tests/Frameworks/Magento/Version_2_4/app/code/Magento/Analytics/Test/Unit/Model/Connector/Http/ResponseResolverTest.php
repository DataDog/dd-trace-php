<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Analytics\Test\Unit\Model\Connector\Http;

use Laminas\Http\Exception\RuntimeException;
use Laminas\Http\Response;
use Magento\Analytics\Model\Connector\Http\ConverterInterface;
use Magento\Analytics\Model\Connector\Http\ResponseHandlerInterface;
use Magento\Analytics\Model\Connector\Http\ResponseResolver;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ResponseResolverTest extends TestCase
{
    /**
     * @var ObjectManagerHelper
     */
    private $objectManagerHelper;

    /**
     * @var ConverterInterface|MockObject
     */
    private $converterMock;

    /**
     * @var ResponseHandlerInterface|MockObject
     */
    private $successResponseHandlerMock;

    /**
     * @var ResponseHandlerInterface|MockObject
     */
    private $notFoundResponseHandlerMock;

    /**
     * @var ResponseResolver
     */
    private $responseResolver;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->converterMock = $this->getMockForAbstractClass(ConverterInterface::class);
        $this->successResponseHandlerMock = $this->getMockBuilder(ResponseHandlerInterface::class)
            ->getMockForAbstractClass();
        $this->notFoundResponseHandlerMock = $this->getMockBuilder(ResponseHandlerInterface::class)
            ->getMockForAbstractClass();
        $this->responseResolver = $this->objectManagerHelper->getObject(
            ResponseResolver::class,
            [
                'converter' => $this->converterMock,
                'responseHandlers' => [
                    201 => $this->successResponseHandlerMock,
                    404 => $this->notFoundResponseHandlerMock,
                ]
            ]
        );
    }

    /**
     * @return void
     * @throws RuntimeException
     */
    public function testGetResultHandleResponseSuccess()
    {
        $expectedBody = ['test' => 'testValue'];
        $response = new Response();
        $response->setStatusCode(Response::STATUS_CODE_201);
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $response->setContent(json_encode($expectedBody));
        $this->converterMock
            ->method('getContentMediaType')
            ->willReturn('application/json');
        $this->successResponseHandlerMock
            ->expects($this->once())
            ->method('handleResponse')
            ->with($expectedBody)
            ->willReturn(true);
        $this->notFoundResponseHandlerMock
            ->expects($this->never())
            ->method('handleResponse');
        $this->converterMock
            ->method('fromBody')
            ->willReturn($expectedBody);
        $this->assertTrue($this->responseResolver->getResult($response));
    }

    /**
     * @return void
     * @throws RuntimeException
     */
    public function testGetResultHandleResponseUnexpectedContentType()
    {
        $expectedBody = 'testString';
        $response = new Response();
        $response->setStatusCode(Response::STATUS_CODE_201);
        $response->getHeaders()->addHeaderLine('Content-Type', 'plain/text');
        $response->setContent($expectedBody);
        $this->converterMock
            ->method('getContentMediaType')
            ->willReturn('application/json');
        $this->converterMock
            ->expects($this->never())
            ->method('fromBody');
        $this->successResponseHandlerMock
            ->expects($this->once())
            ->method('handleResponse')
            ->with([])
            ->willReturn(false);
        $this->notFoundResponseHandlerMock
            ->expects($this->never())
            ->method('handleResponse');
        $this->assertFalse($this->responseResolver->getResult($response));
    }
}
