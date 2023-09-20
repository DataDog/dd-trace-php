<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Translate\Test\Unit;

use \Magento\Framework\Translate\Inline;

class InlineTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\App\ScopeResolverInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $scopeResolverMock;

    /**
     * @var \Magento\Framework\UrlInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $urlMock;

    /**
     * @var \Magento\Framework\View\LayoutInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $layoutMock;

    /**
     * @var \Magento\Framework\Translate\Inline\ConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $configMock;

    /**
     * @var \Magento\Framework\Translate\Inline\ParserFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $parserMock;

    /**
     * @var \Magento\Framework\Translate\Inline\StateInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $stateMock;

    protected function setUp(): void
    {
        $this->scopeResolverMock =
            $this->createMock(\Magento\Framework\App\ScopeResolverInterface::class);
        $this->urlMock = $this->createMock(\Magento\Framework\UrlInterface::class);
        $this->layoutMock = $this->createMock(\Magento\Framework\View\LayoutInterface::class);
        $this->configMock = $this->createMock(\Magento\Framework\Translate\Inline\ConfigInterface::class);
        $this->parserMock = $this->createMock(\Magento\Framework\Translate\Inline\ParserInterface::class);
        $this->stateMock = $this->createMock(\Magento\Framework\Translate\Inline\StateInterface::class);
    }

    /**
     * @param bool $isEnabled
     * @param bool $isActive
     * @param bool $isDevAllowed
     * @param bool $result
     * @dataProvider isAllowedDataProvider
     */
    public function testIsAllowed($isEnabled, $isActive, $isDevAllowed, $result)
    {
        $this->prepareIsAllowed($isEnabled, $isActive, $isDevAllowed);

        $model = new Inline(
            $this->scopeResolverMock,
            $this->urlMock,
            $this->layoutMock,
            $this->configMock,
            $this->parserMock,
            $this->stateMock
        );

        $this->assertEquals($result, $model->isAllowed());
        $this->assertEquals($result, $model->isAllowed());
    }

    /**
     * @return array
     */
    public function isAllowedDataProvider()
    {
        return [
            [true, true, true, true],
            [true, false, true, false],
            [true, true, false, false],
            [true, false, false, false],
            [false, true, true, false],
            [false, false, true, false],
            [false, true, false, false],
            [false, false, false, false],
        ];
    }

    public function testGetParser()
    {
        $model = new Inline(
            $this->scopeResolverMock,
            $this->urlMock,
            $this->layoutMock,
            $this->configMock,
            $this->parserMock,
            $this->stateMock
        );
        $this->assertEquals($this->parserMock, $model->getParser());
    }

    /**
     * @param string|array $body
     * @param string $expected
     * @dataProvider processResponseBodyStripInlineDataProvider
     */
    public function testProcessResponseBodyStripInline($body, $expected)
    {
        $scope = 'admin';
        $this->prepareIsAllowed(false, true, true, $scope);

        $model = new Inline(
            $this->scopeResolverMock,
            $this->urlMock,
            $this->layoutMock,
            $this->configMock,
            $this->parserMock,
            $this->stateMock,
            '',
            '',
            $scope
        );
        $model->processResponseBody($body, true);
        $this->assertEquals($body, $expected);
    }

    /**
     * @return array
     */
    public function processResponseBodyStripInlineDataProvider()
    {
        return [
            ['test', 'test'],
            ['{{{aaaaaa}}{{bbbbb}}{{eeeee}}{{cccccc}}}', 'aaaaaa'],
            [['test1', 'test2'], ['test1', 'test2'],],
            [['{{{aaaaaa}}', 'test3'], ['{{{aaaaaa}}', 'test3'],],
            [['{{{aaaaaa}}{{bbbbb}}', 'test4'], ['{{{aaaaaa}}{{bbbbb}}', 'test4'],],
            [['{{{aaaaaa}}{{bbbbb}}{{eeeee}}{{cccccc}}}', 'test5'], ['aaaaaa', 'test5'],],
        ];
    }

    /**
     * @param string $scope
     * @param array|string $body
     * @param array|string $expected
     * @dataProvider processResponseBodyDataProvider
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function testProcessResponseBody($scope, $body, $expected)
    {
        $isJson = true;
        $this->prepareIsAllowed(true, true, true, $scope);

        $jsonCall = is_array($body) ? 2 * (count($body) + 1)  : 2;
        $this->parserMock->expects(
            $this->exactly($jsonCall)
        )->method(
            'setIsJson'
        )->willReturnMap(
            [
                [$isJson, $this->returnSelf()],
                [!$isJson, $this->returnSelf()],
            ]
        );
        $this->parserMock->expects(
            $this->exactly(1)
        )->method(
            'processResponseBodyString'
        )->with(
            is_array($body) ? reset($body) : $body
        );
        $this->parserMock->expects(
            $this->exactly(2)
        )->method(
            'getContent'
        )->willReturn(
            is_array($body) ? reset($body) : $body
        );

        $model = new Inline(
            $this->scopeResolverMock,
            $this->urlMock,
            $this->layoutMock,
            $this->configMock,
            $this->parserMock,
            $this->stateMock,
            '',
            '',
            $scope
        );

        $model->processResponseBody($body, $isJson);
        $this->assertEquals($body, $expected);
    }

    /**
     * @return array
     */
    public function processResponseBodyDataProvider()
    {
        return [
            ['admin', 'test', 'test'],
            ['not_admin', 'test1', 'test1'],
        ];
    }

    /**
     * @param $scope
     * @param $body
     * @param $expected
     * @dataProvider processResponseBodyGetInlineScriptDataProvider
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function testProcessResponseBodyGetInlineScript($scope, $body, $expected)
    {
        $isJson = true;
        $this->prepareIsAllowed(true, true, true, $scope);

        $jsonCall = is_array($body) ? 2 * (count($body) + 1)  : 2;
        $this->parserMock->expects(
            $this->exactly($jsonCall)
        )->method(
            'setIsJson'
        )->willReturnMap(
            [
                [$isJson, $this->returnSelf()],
                [!$isJson, $this->returnSelf()],
            ]
        );
        $this->parserMock->expects(
            $this->exactly(1)
        )->method(
            'processResponseBodyString'
        )->with(
            is_array($body) ? reset($body) : $body
        );
        $this->parserMock->expects(
            $this->exactly(2)
        )->method(
            'getContent'
        )->willReturn(
            is_array($body) ? reset($body) : $body
        );

        $model = new Inline(
            $this->scopeResolverMock,
            $this->urlMock,
            $this->layoutMock,
            $this->configMock,
            $this->parserMock,
            $this->stateMock,
            '',
            '',
            $scope
        );

        $model->processResponseBody($body, $isJson);
        $this->assertEquals($body, $expected);
    }

    /**
     * @return array
     */
    public function processResponseBodyGetInlineScriptDataProvider()
    {
        return [
            ['admin', 'test', 'test'],
            ['not_admin', 'test1', 'test1'],
        ];
    }

    /**
     * @param bool $isEnabled
     * @param bool $isActive
     * @param bool $isDevAllowed
     * @param null|string $scope
     */
    protected function prepareIsAllowed($isEnabled, $isActive, $isDevAllowed, $scope = null)
    {
        $scopeMock = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $this->stateMock->expects($this->any())->method('isEnabled')->willReturn($isEnabled);
        $this->scopeResolverMock->expects(
            $this->once()
        )->method(
            'getScope'
        )->with(
            $scope
        )->willReturn(
            $scopeMock
        );

        $this->configMock->expects(
            $this->once()
        )->method(
            'isActive'
        )->with(
            $scopeMock
        )->willReturn(
            $isActive
        );

        $this->configMock->expects(
            $this->exactly((int)$isActive)
        )->method(
            'isDevAllowed'
        )->willReturn(
            $isDevAllowed
        );
    }
}
