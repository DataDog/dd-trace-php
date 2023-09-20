<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Test;

use Zend\Stdlib\Parameters;

class RequestTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\TestFramework\Request
     */
    protected $_model = null;

    protected function setUp(): void
    {
        $this->_model = new \Magento\TestFramework\Request(
            $this->createMock(\Magento\Framework\Stdlib\Cookie\CookieReaderInterface::class),
            $this->createMock(\Magento\Framework\Stdlib\StringUtils::class),
            $this->createMock(\Magento\Framework\App\Route\ConfigInterface\Proxy::class),
            $this->createMock(\Magento\Framework\App\Request\PathInfoProcessorInterface::class),
            $this->createMock(\Magento\Framework\ObjectManagerInterface::class)
        );
    }

    public function testGetHttpHost()
    {
        $this->assertEquals('localhost', $this->_model->getHttpHost());
        $this->assertEquals('localhost:81', $this->_model->getHttpHost(false));
    }

    public function testSetGetServerValue()
    {
        $this->_model->setServer(new Parameters([]));
        $this->assertSame([], $this->_model->getServer()->toArray());
        $this->assertSame(
            $this->_model,
            $this->_model->setServer(new Parameters(['test' => 'value', 'null' => null]))
        );
        $this->assertSame(['test' => 'value', 'null' => null], $this->_model->getServer()->toArray());
        $this->assertEquals('value', $this->_model->getServer('test'));
        $this->assertNull($this->_model->getServer('non-existing'));
        $this->assertSame('default', $this->_model->getServer('non-existing', 'default'));
        $this->assertNull($this->_model->getServer('null'));
    }
}
