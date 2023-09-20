<?php
/**
 * Test SOAP controller class.
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Webapi\Test\Unit\Controller;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SoapTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Webapi\Controller\Soap
     */
    protected $_soapController;

    /**
     * @var \Magento\Webapi\Model\Soap\Server
     */
    protected $_soapServerMock;

    /**
     * @var \Magento\Webapi\Model\Soap\Wsdl\Generator
     */
    protected $_wsdlGeneratorMock;

    /**
     * @var \Magento\Framework\Webapi\Request
     */
    protected $_requestMock;

    /**
     * @var \Magento\Framework\Webapi\Response
     */
    protected $_responseMock;

    /**
     * @var \Magento\Framework\Webapi\ErrorProcessor
     */
    protected $_errorProcessorMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\Locale\ResolverInterface
     */
    protected $_localeMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\App\State
     */
    protected $_appStateMock;

    protected $_appconfig;

    /**
     * Set up Controller object.
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->_soapServerMock = $this->getMockBuilder(\Magento\Webapi\Model\Soap\Server::class)
            ->disableOriginalConstructor()
            ->setMethods(['getApiCharset', 'generateUri', 'handle', 'setWSDL', 'setEncoding', 'setReturnResponse'])
            ->getMock();
        $this->_wsdlGeneratorMock = $this->getMockBuilder(\Magento\Webapi\Model\Soap\Wsdl\Generator::class)
            ->disableOriginalConstructor()
            ->setMethods(['generate'])
            ->getMock();
        $this->_requestMock = $this->getMockBuilder(\Magento\Framework\Webapi\Request::class)
            ->disableOriginalConstructor()
            ->setMethods(['getParams', 'getParam', 'getRequestedServices', 'getHttpHost'])
            ->getMock();
        $this->_requestMock->expects($this->any())
            ->method('getHttpHost')
            ->willReturn('testHostName.com');
        $this->_responseMock = $this->getMockBuilder(\Magento\Framework\Webapi\Response::class)
            ->disableOriginalConstructor()
            ->setMethods(['clearHeaders', 'setHeader', 'sendResponse', 'getHeaders'])
            ->getMock();
        $this->_errorProcessorMock = $this->getMockBuilder(\Magento\Framework\Webapi\ErrorProcessor::class)
            ->disableOriginalConstructor()
            ->setMethods(['maskException'])
            ->getMock();

        $this->_appStateMock =  $this->createMock(\Magento\Framework\App\State::class);

        $localeResolverMock = $this->getMockBuilder(
            \Magento\Framework\Locale\Resolver::class
        )->disableOriginalConstructor()->setMethods(
            ['getLocale']
        )->getMock();
        $localeResolverMock->expects($this->any())->method('getLocale')->willReturn('en');

        $this->_responseMock->expects($this->any())->method('clearHeaders')->willReturnSelf();
        $this->_responseMock
            ->expects($this->any())
            ->method('getHeaders')
            ->willReturn(new \Zend\Http\Headers());

        $appconfig = $this->createMock(\Magento\Framework\App\Config::class);
        $objectManagerHelper->setBackwardCompatibleProperty(
            $this->_requestMock,
            'appConfig',
            $appconfig
        );

        $this->_soapServerMock->expects($this->any())->method('setWSDL')->willReturnSelf();
        $this->_soapServerMock->expects($this->any())->method('setEncoding')->willReturnSelf();
        $this->_soapServerMock->expects($this->any())->method('setReturnResponse')->willReturnSelf();
        $pathProcessorMock = $this->createMock(\Magento\Webapi\Controller\PathProcessor::class);
        $areaListMock = $this->createMock(\Magento\Framework\App\AreaList::class);
        $areaMock = $this->createMock(\Magento\Framework\App\AreaInterface::class);
        $areaListMock->expects($this->any())->method('getArea')->willReturn($areaMock);

        $rendererMock = $this->getMockBuilder(\Magento\Framework\Webapi\Rest\Response\RendererFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->_soapController = new \Magento\Webapi\Controller\Soap(
            $this->_requestMock,
            $this->_responseMock,
            $this->_wsdlGeneratorMock,
            $this->_soapServerMock,
            $this->_errorProcessorMock,
            $this->_appStateMock,
            $localeResolverMock,
            $pathProcessorMock,
            $rendererMock,
            $areaListMock
        );
    }

    /**
     * Test successful WSDL content generation.
     */
    public function testDispatchWsdl()
    {
        $params = [
            \Magento\Webapi\Model\Soap\Server::REQUEST_PARAM_WSDL => 1,
            \Magento\Framework\Webapi\Request::REQUEST_PARAM_SERVICES => 'foo',
        ];
        $this->_mockGetParam(\Magento\Webapi\Model\Soap\Server::REQUEST_PARAM_WSDL, 1);
        $this->_requestMock->expects($this->once())
            ->method('getParams')
            ->willReturn($params);
        $wsdl = 'Some WSDL content';
        $this->_wsdlGeneratorMock->expects($this->any())->method('generate')->willReturn($wsdl);

        $this->_soapController->dispatch($this->_requestMock);
        $this->assertEquals($wsdl, $this->_responseMock->getBody());
    }

    public function testDispatchInvalidWsdlRequest()
    {
        $params = [
            \Magento\Webapi\Model\Soap\Server::REQUEST_PARAM_WSDL => 1,
            'param_1' => 'foo',
            'param_2' => 'bar,'
        ];
        $this->_mockGetParam(\Magento\Webapi\Model\Soap\Server::REQUEST_PARAM_WSDL, 1);
        $this->_requestMock->expects($this->once())
            ->method('getParams')
            ->willReturn($params);
        $this->_errorProcessorMock->expects(
            $this->any()
        )->method(
            'maskException'
        )->willReturn(
            new \Magento\Framework\Webapi\Exception(__('message'))
        );
        $wsdl = 'Some WSDL content';
        $this->_wsdlGeneratorMock->expects($this->any())->method('generate')->willReturn($wsdl);
        $encoding = "utf-8";
        $this->_soapServerMock->expects($this->any())->method('getApiCharset')->willReturn($encoding);
        $this->_soapController->dispatch($this->_requestMock);

        $expectedMessage = <<<EXPECTED_MESSAGE
<?xml version="1.0" encoding="{$encoding}"?>
<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope" >
   <env:Body>
      <env:Fault>
         <env:Code>
            <env:Value>env:Sender</env:Value>
         </env:Code>
         <env:Reason>
            <env:Text xml:lang="en">message</env:Text>
         </env:Reason>
      </env:Fault>
   </env:Body>
</env:Envelope>
EXPECTED_MESSAGE;
        $this->assertXmlStringEqualsXmlString($expectedMessage, $this->_responseMock->getBody());
    }

    /**
     * Test successful SOAP action request dispatch.
     */
    public function testDispatchSoapRequest()
    {
        $this->_soapServerMock->expects($this->once())->method('handle');
        $response = $this->_soapController->dispatch($this->_requestMock);
        $this->assertEquals(200, $response->getHttpResponseCode());
    }

    /**
     * Test handling exception during dispatch.
     */
    public function testDispatchWithException()
    {
        $exceptionMessage = 'some error message';
        $exception = new \Magento\Framework\Webapi\Exception(__($exceptionMessage));
        $this->_soapServerMock->expects($this->any())->method('handle')->will($this->throwException($exception));
        $this->_errorProcessorMock->expects(
            $this->any()
        )->method(
            'maskException'
        )->willReturn(
            $exception
        );
        $encoding = "utf-8";
        $this->_soapServerMock->expects($this->any())->method('getApiCharset')->willReturn($encoding);

        $this->_soapController->dispatch($this->_requestMock);

        $expectedMessage = <<<EXPECTED_MESSAGE
<?xml version="1.0" encoding="{$encoding}"?>
<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope" >
   <env:Body>
      <env:Fault>
         <env:Code>
            <env:Value>env:Sender</env:Value>
         </env:Code>
         <env:Reason>
            <env:Text xml:lang="en">some error message</env:Text>
         </env:Reason>
      </env:Fault>
   </env:Body>
</env:Envelope>
EXPECTED_MESSAGE;
        $this->assertXmlStringEqualsXmlString($expectedMessage, $this->_responseMock->getBody());
    }

    /**
     * Mock getParam() of request object to return given value.
     *
     * @param $param
     * @param $value
     */
    protected function _mockGetParam($param, $value)
    {
        $this->_requestMock->expects(
            $this->any()
        )->method(
            'getParam'
        )->with(
            $param
        )->willReturn(
            $value
        );
    }
}
