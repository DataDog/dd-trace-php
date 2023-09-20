<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\View\Test\Unit\Element\Js;

class CookieTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\Element\Js\Cookie
     */
    protected $model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\View\Element\Template\Context
     */
    protected $contextMock;

    /**
     * @var \Magento\Framework\Session\Config\ConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $sessionConfigMock;

    /**
     * @var \Magento\Framework\Validator\Ip|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $ipValidatorMock;

    protected function setUp(): void
    {
        $this->contextMock = $this->getMockBuilder(\Magento\Framework\View\Element\Template\Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->sessionConfigMock = $this->getMockBuilder(\Magento\Framework\Session\Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->ipValidatorMock = $this->getMockBuilder(\Magento\Framework\Validator\Ip::class)
            ->disableOriginalConstructor()
            ->getMock();

        $validtorMock = $this->getMockBuilder(\Magento\Framework\View\Element\Template\File\Validator::class)
            ->setMethods(['isValid'])->disableOriginalConstructor()->getMock();

        $scopeConfigMock = $this->getMockBuilder(\Magento\Framework\App\Config::class)
            ->setMethods(['isSetFlag'])->disableOriginalConstructor()->getMock();

        $this->contextMock->expects($this->any())
            ->method('getScopeConfig')
            ->willReturn($scopeConfigMock);

        $this->contextMock->expects($this->any())
            ->method('getValidator')
            ->willReturn($validtorMock);

        $this->model = new \Magento\Framework\View\Element\Js\Cookie(
            $this->contextMock,
            $this->sessionConfigMock,
            $this->ipValidatorMock,
            []
        );
    }

    public function testInstanceOf()
    {
        $this->assertInstanceOf(\Magento\Framework\View\Element\Js\Cookie::class, $this->model);
    }

    /**
     * @dataProvider domainDataProvider
     */
    public function testGetDomain($domain, $isIp, $expectedResult)
    {
        $this->sessionConfigMock->expects($this->once())
            ->method('getCookieDomain')
            ->willReturn($domain);
        $this->ipValidatorMock->expects($this->once())
            ->method('isValid')
            ->with($this->equalTo($domain))
            ->willReturn($isIp);

        $result = $this->model->getDomain($domain);
        $this->assertEquals($expectedResult, $result);
    }

    /**
     * @return array
     */
    public static function domainDataProvider()
    {
        return [
            ['127.0.0.1', true, '127.0.0.1'],
            ['example.com', false, '.example.com'],
            ['.example.com', false, '.example.com'],
        ];
    }

    public function testGetPath()
    {
        $path = 'test_path';

        $this->sessionConfigMock->expects($this->once())
            ->method('getCookiePath')
            ->willReturn($path);

        $result = $this->model->getPath();
        $this->assertEquals($path, $result);
    }

    public function testGetLifetime()
    {
        $lifetime = 3600;
        $this->sessionConfigMock->expects(static::once())
            ->method('getCookieLifetime')
            ->willReturn($lifetime);

        $this->assertEquals($lifetime, $this->model->getLifetime());
    }
}
