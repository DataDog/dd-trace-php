<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Webapi\Test\Unit\Controller;

use Magento\Framework\App\AreaInterface;
use Magento\Framework\App\AreaList;
use Magento\Framework\Oauth\OauthInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Reflection\DataObjectProcessor;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\Webapi\Authorization;
use Magento\Framework\Webapi\ErrorProcessor;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;
use Magento\Framework\Webapi\ServiceInputProcessor;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Webapi\Controller\Rest;
use Magento\Webapi\Controller\Rest\ParamsOverrider;
use Magento\Webapi\Controller\Rest\RequestProcessorPool;
use Magento\Webapi\Controller\Rest\Router;
use Magento\Webapi\Controller\Rest\Router\Route;
use Magento\Webapi\Controller\Rest\SchemaRequestProcessor;
use Magento\Webapi\Controller\Rest\SynchronousRequestProcessor;
use Magento\Webapi\Model\Rest\Swagger\Generator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Test Rest controller.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class RestTest extends TestCase
{
    /**
     * @var Rest
     */
    protected $_restController;

    /**
     * @var Request|MockObject
     */
    protected $_requestMock;

    /**
     * @var Response|MockObject
     */
    protected $_responseMock;

    /**
     * @var MockObject|Route
     */
    protected $_routeMock;

    /**
     * @var \stdClass|MockObject
     */
    protected $_serviceMock;

    /**
     * @var OauthInterface|MockObject
     */
    protected $_oauthServiceMock;

    /**
     * @var Authorization|MockObject
     */
    protected $_authorizationMock;

    /**
     * @var ServiceInputProcessor|MockObject
     */
    protected $serviceInputProcessorMock;

    /**
     * @var Generator|MockObject
     */
    protected $swaggerGeneratorMock;

    /**
     * @var  StoreManagerInterface|MockObject
     */
    private $storeManagerMock;

    /**
     * @var  StoreInterface|MockObject
     */
    private $storeMock;

    /**
     * @var  SchemaRequestProcessor|MockObject
     */
    protected $schemaRequestProcessor;

    /**
     * @var  SynchronousRequestProcessor|MockObject
     */
    protected $synchronousRequestProcessor;

    /**
     * @var  RequestProcessorPool|MockObject
     */
    protected $requestProcessorPool;

    const SERVICE_METHOD = 'testMethod';

    const SERVICE_ID = Rest::class;

    protected function setUp(): void
    {
        $objectManagerMock = $this->getMockForAbstractClass(ObjectManagerInterface::class);
        $this->_requestMock = $this->getRequestMock();
        $this->_requestMock->expects($this->any())->method('getHttpHost')->willReturn('testHostName.com');
        $this->_responseMock = $this->getResponseMock();
        $routerMock = $this->getMockBuilder(Router::class)
            ->setMethods(['match'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->_routeMock = $this->getRouteMock();
        $this->_serviceMock = $this->getMockBuilder(self::SERVICE_ID)
            ->setMethods([self::SERVICE_METHOD])
            ->disableOriginalConstructor()
            ->getMock();

        $this->_oauthServiceMock = $this->getMockBuilder(OauthInterface::class)
            ->setMethods(['validateAccessTokenRequest'])->getMockForAbstractClass();
        $this->_authorizationMock = $this->getMockBuilder(Authorization::class)
            ->disableOriginalConstructor()
            ->getMock();

        $paramsOverriderMock = $this->getMockBuilder(ParamsOverrider::class)
            ->setMethods(['overrideParams'])
            ->disableOriginalConstructor()
            ->getMock();

        $dataObjectProcessorMock = $this->getMockBuilder(DataObjectProcessor::class)
            ->disableOriginalConstructor()
            ->setMethods(['getMethodReturnType'])
            ->getMockForAbstractClass();

        $layoutMock = $this->getMockBuilder(LayoutInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $errorProcessorMock = $this->createMock(ErrorProcessor::class);
        $errorProcessorMock->expects($this->any())->method('maskException')->willReturnArgument(0);

        $objectManager = new ObjectManager($this);

        $this->serviceInputProcessorMock = $this->getMockBuilder(ServiceInputProcessor::class)
            ->disableOriginalConstructor()
            ->setMethods(['process'])->getMock();

        $areaListMock = $this->createMock(AreaList::class);
        $areaMock = $this->getMockForAbstractClass(AreaInterface::class);
        $areaListMock->expects($this->any())->method('getArea')->willReturn($areaMock);
        $this->storeMock = $this->getMockForAbstractClass(StoreInterface::class);
        $this->storeManagerMock = $this->getMockForAbstractClass(StoreManagerInterface::class);
        $this->storeManagerMock->expects($this->any())->method('getStore')->willReturn($this->storeMock);
        $this->requestProcessorPool = $this->getRequestProccessotPoolMock();

        $this->_restController =
            $objectManager->getObject(
                Rest::class,
                [
                    'request'               => $this->_requestMock,
                    'response'              => $this->_responseMock,
                    'router'                => $routerMock,
                    'objectManager'         => $objectManagerMock,
                    'layout'                => $layoutMock,
                    'oauthService'          => $this->_oauthServiceMock,
                    'authorization'         => $this->_authorizationMock,
                    'serviceInputProcessor' => $this->serviceInputProcessorMock,
                    'errorProcessor'        => $errorProcessorMock,
                    'areaList'              => $areaListMock,
                    'paramsOverrider'       => $paramsOverriderMock,
                    'dataObjectProcessor'   => $dataObjectProcessorMock,
                    'storeManager'          => $this->storeManagerMock,
                    'requestProcessorPool'  => $this->requestProcessorPool,
                ]
            );

        $this->_routeMock->expects($this->any())->method('getServiceClass')->willReturn(self::SERVICE_ID);
        $this->_routeMock->expects($this->any())->method('getServiceMethod')
            ->willReturn(self::SERVICE_METHOD);

        $routerMock->expects($this->any())->method('match')->willReturn($this->_routeMock);

        $objectManagerMock->expects($this->any())->method('get')->willReturn($this->_serviceMock);
        $this->_responseMock->expects($this->any())->method('prepareResponse')->willReturn([]);
        $this->_serviceMock->expects($this->any())->method(self::SERVICE_METHOD)->willReturn(null);

        $dataObjectProcessorMock->expects($this->any())->method('getMethodReturnType')
            ->with(self::SERVICE_ID, self::SERVICE_METHOD)
            ->willReturn('null');

        $paramsOverriderMock->expects($this->any())->method('overrideParams')->willReturn([]);

        parent::setUp();
    }

    public function testDispatchSchemaRequest()
    {
        $params = [
            \Magento\Framework\Webapi\Request::REQUEST_PARAM_SERVICES => 'foo',
        ];
        $this->_requestMock->expects($this->any())
            ->method('getPathInfo')
            ->willReturn(SchemaRequestProcessor::PROCESSOR_PATH);

        $this->_requestMock->expects($this->any())
            ->method('getParams')
            ->willReturn($params);

        $schema = 'Some REST schema content';
        $this->swaggerGeneratorMock->expects($this->any())->method('generate')->willReturn($schema);
        $this->requestProcessorPool->getProcessor($this->_requestMock)->process($this->_requestMock);

        $this->assertEquals($schema, $this->_responseMock->getBody());
    }

    public function testDispatchAllSchemaRequest()
    {
        $params = [
            \Magento\Framework\Webapi\Request::REQUEST_PARAM_SERVICES => 'all',
        ];
        $this->_requestMock->expects($this->any())
            ->method('getPathInfo')
            ->willReturn(SchemaRequestProcessor::PROCESSOR_PATH);
        $this->_requestMock->expects($this->any())
            ->method('getParam')
            ->willReturnMap(
                [
                    [
                        \Magento\Framework\Webapi\Request::REQUEST_PARAM_SERVICES,
                        null,
                        'all',
                    ],
                ]
            );
        $this->_requestMock->expects($this->any())
            ->method('getParams')
            ->willReturn($params);
        $this->_requestMock->expects($this->any())
            ->method('getRequestedServices')
            ->willReturn('all');

        $schema = 'Some REST schema content';
        $this->swaggerGeneratorMock->expects($this->any())->method('generate')->willReturn($schema);
        $this->requestProcessorPool->getProcessor($this->_requestMock)->process($this->_requestMock);

        $this->assertEquals($schema, $this->_responseMock->getBody());
    }

    /**
     * @return object|RequestProcessorPool
     */
    private function getRequestProccessotPoolMock()
    {
        $objectManager = new ObjectManager($this);

        $this->swaggerGeneratorMock = $this->getMockBuilder(Generator::class)
            ->disableOriginalConstructor()
            ->setMethods(['generate', 'getListOfServices'])
            ->getMockForAbstractClass();

        $this->schemaRequestProcessor = $objectManager->getObject(
            SchemaRequestProcessor::class,
            [
                'swaggerGenerator' => $this->swaggerGeneratorMock,
                'response'         => $this->_responseMock,
            ]
        );

        $this->synchronousRequestProcessor =
            $this->getMockBuilder(SynchronousRequestProcessor::class)
                ->setMethods(['process'])
                ->disableOriginalConstructor()
                ->getMock();

        return $objectManager->getObject(
            RequestProcessorPool::class,
            [
                'requestProcessors' => [
                    'syncSchema' => $this->schemaRequestProcessor,
                    'sync'       => $this->synchronousRequestProcessor,
                ],
            ]
        );
    }

    /**
     * @return Route|MockObject
     */
    private function getRouteMock()
    {
        return $this->getMockBuilder(Route::class)
            ->setMethods([
                'isSecure',
                'getServiceMethod',
                'getServiceClass',
                'getAclResources',
                'getParameters',
            ])
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @return Request|MockObject
     */
    private function getRequestMock()
    {
        return $this->getMockBuilder(Request::class)
            ->setMethods(
                [
                    'isSecure',
                    'getRequestData',
                    'getParams',
                    'getParam',
                    'getRequestedServices',
                    'getPathInfo',
                    'getHttpHost',
                    'getMethod',
                ]
            )->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @return Response|MockObject
     */
    private function getResponseMock()
    {
        return $this->getMockBuilder(Response::class)
            ->setMethods(['sendResponse', 'prepareResponse', 'setHeader'])
            ->disableOriginalConstructor()
            ->getMock();
    }
}
