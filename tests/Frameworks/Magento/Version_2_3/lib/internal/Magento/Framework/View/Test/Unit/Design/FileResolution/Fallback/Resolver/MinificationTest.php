<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\View\Test\Unit\Design\FileResolution\Fallback\Resolver;

use Magento\Framework\View\Design\FileResolution\Fallback\Resolver\Minification;
use Magento\Framework\View\Design\FileResolution\Fallback\ResolverInterface;

/**
 * Unit test for Magento\Framework\View\Design\FileResolution\Fallback\Resolver\Minification
 */
class MinificationTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\Design\FileResolution\Fallback\Resolver\Minification
     */
    protected $minification;

    /**
     * @var ResolverInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resolverMock;

    /**
     * @var \Magento\Framework\View\Asset\Minification|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $assetMinificationMock;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        $this->resolverMock = $this
            ->getMockBuilder(\Magento\Framework\View\Design\FileResolution\Fallback\ResolverInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->assetMinificationMock = $this->getMockBuilder(\Magento\Framework\View\Asset\Minification::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->minification = new Minification(
            $this->resolverMock,
            $this->assetMinificationMock
        );
    }

    /**
     * @param bool $isEnabled
     * @param string $requested
     * @param string $alternative
     * @param string $expected
     * @param string $resolvedOriginal
     * @param string $resolvedAlternative
     * @return void
     * @dataProvider resolveDataProvider
     */
    public function testResolve(
        $isEnabled,
        $requested,
        $alternative,
        $expected,
        $resolvedOriginal,
        $resolvedAlternative
    ) {
        $this->assetMinificationMock
            ->expects($this->any())
            ->method('isEnabled')
            ->willReturn($isEnabled);
        $this->assetMinificationMock
            ->expects($this->any())
            ->method('addMinifiedSign')
            ->with($requested)
            ->willReturn($alternative);

        $this->resolverMock
            ->expects($this->any())
            ->method('resolve')
            ->willReturnMap([
                ['', $requested, null, null, null, null, $resolvedOriginal],
                ['', $alternative, null, null, null, null, $resolvedAlternative]
            ]);

        $this->assertEquals($expected, $this->minification->resolve('', $requested));
    }

    /**
     * @return array
     */
    public function resolveDataProvider()
    {
        return [
            [true, 'file.css', 'file.min.css', 'found.min.css', false, 'found.min.css'],
            [false, 'file.min.css', 'file.min.css', false, false, 'found.css'],
            [true, 'file.js', 'file.min.js', 'found.min.js', false, 'found.min.js'],
            [false, 'file.min.js', 'file.min.js', false, false, 'found.js'],
        ];
    }
}
