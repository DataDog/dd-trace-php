<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\View\Test\Unit;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

class DesignExceptionsTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Framework\View\DesignExceptions */
    private $designExceptions;

    /** @var ObjectManagerHelper */
    private $objectManagerHelper;

    /** @var \Magento\Framework\App\Config\ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfigMock;

    /** @var \Magento\Framework\App\Request\Http|\PHPUnit\Framework\MockObject\MockObject */
    private $requestMock;

    /** @var string */
    private $exceptionConfigPath = 'exception_path';

    /** @var string */
    private $scopeType = 'scope_type';

    /** @var Json|\PHPUnit\Framework\MockObject\MockObject */
    private $serializerMock;

    protected function setUp(): void
    {
        $this->scopeConfigMock = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $this->requestMock = $this->createMock(\Magento\Framework\App\Request\Http::class);
        $this->serializerMock = $this->createMock(Json::class);

        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->designExceptions = $this->objectManagerHelper->getObject(
            \Magento\Framework\View\DesignExceptions::class,
            [
                'scopeConfig' => $this->scopeConfigMock,
                'exceptionConfigPath' => $this->exceptionConfigPath,
                'scopeType' => $this->scopeType,
                'serializer' => $this->serializerMock,
            ]
        );
    }

    /**
     * @param string $userAgent
     * @param bool $configValue
     * @param int $callNum
     * @param bool|string $result
     * @param array $expressions
     * @dataProvider getThemeByRequestDataProvider
     */
    public function testGetThemeByRequest($userAgent, $configValue, $callNum, $result, $expressions = [])
    {
        $this->requestMock->expects($this->once())
            ->method('getServer')
            ->with($this->equalTo('HTTP_USER_AGENT'))
            ->willReturn($userAgent);

        if ($userAgent) {
            $this->scopeConfigMock->expects($this->once())
                ->method('getValue')
                ->with($this->equalTo($this->exceptionConfigPath), $this->equalTo($this->scopeType))
                ->willReturn($configValue);
        }

        $this->serializerMock->expects($this->exactly($callNum))
            ->method('unserialize')
            ->with($configValue)
            ->willReturn($expressions);

        $this->assertSame($result, $this->designExceptions->getThemeByRequest($this->requestMock));
    }

    /**
     * @return array
     */
    public function getThemeByRequestDataProvider()
    {
        return [
            [false, null, 0, false],
            ['iphone', null, 0, false],
            ['iphone', 'serializedExpressions1', 1, false],
            ['iphone', 'serializedExpressions2', 1, 'matched', [['regexp' => '/iphone/', 'value' => 'matched']]],
            ['explorer', 'serializedExpressions3', 1, false, [['regexp' => '/iphone/', 'value' => 'matched']]],
        ];
    }
}
