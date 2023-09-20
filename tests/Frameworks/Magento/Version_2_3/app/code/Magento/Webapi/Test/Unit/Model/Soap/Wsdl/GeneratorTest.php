<?php
/**
 * Tests for \Magento\Webapi\Model\Soap\Wsdl\Generator.
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Webapi\Test\Unit\Model\Soap\Wsdl;

/**
 * Class GeneratorTest
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class GeneratorTest extends \PHPUnit\Framework\TestCase
{
    /**  @var \Magento\Webapi\Model\Soap\Wsdl\Generator */
    protected $_wsdlGenerator;

    /**
     * @var \Magento\Framework\Webapi\CustomAttributeTypeLocatorInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $customAttributeTypeLocator = null;

    /**  @var \Magento\Webapi\Model\ServiceMetadata|\PHPUnit\Framework\MockObject\MockObject */
    protected $serviceMetadata;

    /**  @var \Magento\Webapi\Model\Soap\WsdlFactory|\PHPUnit\Framework\MockObject\MockObject */
    protected $_wsdlFactoryMock;

    /** @var \Magento\Webapi\Model\Cache\Type\Webapi|\PHPUnit\Framework\MockObject\MockObject */
    protected $_cacheMock;

    /** @var \Magento\Framework\Reflection\TypeProcessor|\PHPUnit\Framework\MockObject\MockObject */
    protected $_typeProcessor;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $serializer;

    protected function setUp(): void
    {
        $this->serviceMetadata = $this->getMockBuilder(
            \Magento\Webapi\Model\ServiceMetadata::class
        )->disableOriginalConstructor()->getMock();

        $_wsdlMock = $this->getMockBuilder(
            \Magento\Webapi\Model\Soap\Wsdl::class
        )->disableOriginalConstructor()->setMethods(
            [
                'addSchemaTypeSection',
                'addService',
                'addPortType',
                'addBinding',
                'addSoapBinding',
                'addElement',
                'addComplexType',
                'addMessage',
                'addPortOperation',
                'addBindingOperation',
                'addSoapOperation',
                'toXML',
            ]
        )->getMock();
        $this->_wsdlFactoryMock = $this->getMockBuilder(
            \Magento\Webapi\Model\Soap\WsdlFactory::class
        )->setMethods(
            ['create']
        )->disableOriginalConstructor()->getMock();
        $this->_wsdlFactoryMock->expects($this->any())->method('create')->willReturn($_wsdlMock);

        $this->_cacheMock = $this->getMockBuilder(
            \Magento\Webapi\Model\Cache\Type\Webapi::class
        )->disableOriginalConstructor()->getMock();
        $this->_cacheMock->expects($this->any())->method('load')->willReturn(false);
        $this->_cacheMock->expects($this->any())->method('save')->willReturn(true);

        $this->_typeProcessor = $this->createMock(\Magento\Framework\Reflection\TypeProcessor::class);

        /** @var \Magento\Framework\Webapi\Authorization|\PHPUnit\Framework\MockObject\MockObject $authorizationMock */
        $authorizationMock = $this->getMockBuilder(\Magento\Framework\Webapi\Authorization::class)
            ->disableOriginalConstructor()
            ->getMock();
        $authorizationMock->expects($this->any())->method('isAllowed')->willReturn(true);

        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->customAttributeTypeLocator = $objectManager
            ->getObject(\Magento\Eav\Model\TypeLocator::class);

        $this->serializer = $this->getMockBuilder(\Magento\Framework\Serialize\Serializer\Json::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->serializer->expects($this->any())
            ->method('serialize')
            ->willReturnCallback(
                function ($value) {
                    return json_encode($value);
                }
            );
        $objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $objectManagerMock->expects($this->any())
            ->method('get')
            ->willReturnMap([
                [\Magento\Framework\Serialize\Serializer\Json::class, $this->serializer]
            ]);
        \Magento\Framework\App\ObjectManager::setInstance($objectManagerMock);

        $this->_wsdlGenerator = $objectManager->getObject(
            \Magento\Webapi\Model\Soap\Wsdl\Generator::class,
            [
                'wsdlFactory' => $this->_wsdlFactoryMock,
                'cache' => $this->_cacheMock,
                'typeProcessor' => $this->_typeProcessor,
                'customAttributeTypeLocator' => $this->customAttributeTypeLocator,
                'serviceMetadata' => $this->serviceMetadata,
                'authorization' => $authorizationMock,
                'serializer' => $this->serializer
            ]
        );

        parent::setUp();
    }

    /**
     * Test getElementComplexTypeName
     */
    public function testGetElementComplexTypeName()
    {
        $this->assertEquals("Test", $this->_wsdlGenerator->getElementComplexTypeName("test"));
    }

    /**
     * Test getPortTypeName
     */
    public function testGetPortTypeName()
    {
        $this->assertEquals("testPortType", $this->_wsdlGenerator->getPortTypeName("test"));
    }

    /**
     * Test getBindingName
     */
    public function testGetBindingName()
    {
        $this->assertEquals("testBinding", $this->_wsdlGenerator->getBindingName("test"));
    }

    /**
     * Test getPortName
     */
    public function testGetPortName()
    {
        $this->assertEquals("testPort", $this->_wsdlGenerator->getPortName("test"));
    }

    /**
     * test getServiceName
     */
    public function testGetServiceName()
    {
        $this->assertEquals("testService", $this->_wsdlGenerator->getServiceName("test"));
    }

    /**
     * @test
     */
    public function testGetInputMessageName()
    {
        $this->assertEquals("operationNameRequest", $this->_wsdlGenerator->getInputMessageName("operationName"));
    }

    /**
     * @test
     */
    public function testGetOutputMessageName()
    {
        $this->assertEquals("operationNameResponse", $this->_wsdlGenerator->getOutputMessageName("operationName"));
    }

    /**
     * Test exception for handle
     *
     * @covers \Magento\Webapi\Model\AbstractSchemaGenerator::generate()
     */
    public function testHandleWithException()
    {
        $this->expectException(\Magento\Framework\Webapi\Exception::class);
        $this->expectExceptionMessage('exception message');

        $genWSDL = 'generatedWSDL';
        $exceptionMsg = 'exception message';
        $requestedService = ['catalogProduct'];
        $serviceMetadata = ['methods' => ['methodName' => ['interface' => 'aInterface', 'resources' => ['anonymous']]]];

        $this->serviceMetadata->expects($this->any())
            ->method('getServiceMetadata')
            ->willReturn($serviceMetadata);
        $this->_typeProcessor->expects($this->once())
            ->method('processInterfaceCallInfo')
            ->willThrowException(new \Magento\Framework\Webapi\Exception(__($exceptionMsg)));

        $this->assertEquals(
            $genWSDL,
            $this->_wsdlGenerator->generate($requestedService, 'http://', 'magento.host', '/soap/default')
        );
    }
}
