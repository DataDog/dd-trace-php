<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\View\Test\Unit\Asset\PreProcessor;

use Magento\Framework\View\Asset\PreProcessor\Minify;
use Magento\Framework\View\Asset\PreProcessor\MinificationConfigProvider;

/**
 * Unit test for Magento\Framework\View\Asset\PreProcessor\Minify
 */
class MinifyTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\Asset\PreProcessor\Minify
     */
    protected $minify;

    /**
     * @var \Magento\Framework\Code\Minifier\AdapterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $adapterMock;

    /**
     * @var \Magento\Framework\View\Asset\Minification|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $minificationMock;

    /**
     * @var MinificationConfigProvider|\PHPUnit\Framework\MockObject\MockObject
     */
    private $minificationConfigMock;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        $this->adapterMock = $this->getMockBuilder(\Magento\Framework\Code\Minifier\AdapterInterface::class)
            ->setMethods(['minify'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->minificationMock = $this->getMockBuilder(\Magento\Framework\View\Asset\Minification::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->minificationConfigMock = $this->getMockBuilder(MinificationConfigProvider::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->minify = new Minify(
            $this->adapterMock,
            $this->minificationMock,
            $this->minificationConfigMock
        );
    }

    /**
     * @param string $targetPath
     * @param string $originalPath
     * @param int $minifyCalls
     * @param int $setContentCalls
     * @param bool $isEnabled
     * @return void
     * @dataProvider processDataProvider
     */
    public function testProcess($targetPath, $originalPath, $minifyCalls, $setContentCalls, $isEnabled)
    {
        $chainMock = $this->getMockBuilder(\Magento\Framework\View\Asset\PreProcessor\Chain::class)
            ->disableOriginalConstructor()
            ->getMock();
        $chainMock
            ->expects($this->any())
            ->method('getTargetAssetPath')
            ->willReturn($targetPath);
        $chainMock
            ->expects($this->exactly($setContentCalls))
            ->method('setContent')
            ->with('minified content');
        $chainMock
            ->expects($this->any())
            ->method('getContent')
            ->willReturn('original content');
        $chainMock
            ->expects($this->any())
            ->method('getOrigAssetPath')
            ->willReturn($originalPath);

        $this->adapterMock
            ->expects($this->exactly($minifyCalls))
            ->method('minify')
            ->with('original content')
            ->willReturn('minified content');

        $this->minificationConfigMock
            ->expects($this->any())
            ->method('isMinificationEnabled')
            ->willReturnMap([[$targetPath, $isEnabled]]);

        $this->minificationMock
            ->expects($this->any())
            ->method('isMinifiedFilename')
            ->willReturnMap(
                [
                    ['test.min.css', true],
                    ['test.jpeg', false],
                    ['test.css', false]
                ]
            );

        $this->minify->process($chainMock);
    }

    /**
     * @return array
     */
    public function processDataProvider()
    {
        return [
            ['test.min.css', 'test.css', 1, 1, true],
            ['test.min.css', 'test.min.css', 0, 0, true],
            ['test.jpeg', 'test.jpeg', 0, 0, true],
            ['test.css', 'test.css', 0, 0, true],
            ['test.jpeg', 'test.jpeg', 0, 0, true],
            ['test.css', 'test.css', 0, 0, true],
            ['test.css', 'test.css', 0, 0, false]
        ];
    }
}
