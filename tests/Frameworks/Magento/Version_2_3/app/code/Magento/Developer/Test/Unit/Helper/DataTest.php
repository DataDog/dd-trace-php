<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Developer\Test\Unit\Helper;

class DataTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Developer\Helper\Data
     */
    protected $helper;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $scopeConfigMock;

    /**
     * @var \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $remoteAddressMock;

    /**
     * @var \Magento\Framework\HTTP\Header | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $httpHeaderMock;

    protected function setUp(): void
    {
        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $className = \Magento\Developer\Helper\Data::class;
        $arguments = $objectManagerHelper->getConstructArguments($className);
        /** @var \Magento\Framework\App\Helper\Context $context */
        $context = $arguments['context'];
        $this->scopeConfigMock = $context->getScopeConfig();
        $this->remoteAddressMock = $context->getRemoteAddress();
        $this->httpHeaderMock = $context->getHttpHeader();
        $this->helper = $objectManagerHelper->getObject($className, $arguments);
    }

    /**
     * @param array $allowedIps
     * @param bool $expected
     * @dataProvider isDevAllowedDataProvider
     */
    public function testIsDevAllowed($allowedIps, $expected, $callNum = 1)
    {
        $storeId = 'storeId';

        $this->scopeConfigMock->expects($this->once())
            ->method('getValue')
            ->with(
                \Magento\Developer\Helper\Data::XML_PATH_DEV_ALLOW_IPS,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $storeId
            )->willReturn($allowedIps);

        $this->remoteAddressMock->expects($this->once())
            ->method('getRemoteAddress')
            ->willReturn('remoteAddress');

        $this->httpHeaderMock->expects($this->exactly($callNum))
            ->method('getHttpHost')
            ->willReturn('httpHost');

        $this->assertEquals($expected, $this->helper->isDevAllowed($storeId));
    }

    /**
     * @return array
     */
    public function isDevAllowedDataProvider()
    {
        return [
            'allow_nothing' => [
                '',
                true,
                0,
            ],
            'allow_remote_address' => [
                'ip1, ip2, remoteAddress',
                true,
                0,
            ],
            'allow_http_host' => [
                'ip1, ip2, httpHost',
                true,
            ],
            'allow_neither' => [
                'ip1, ip2, ip3',
                false,
            ],
        ];
    }
}
