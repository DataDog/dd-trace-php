<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\View\Test\Unit\Asset;

use Magento\Framework\View\Asset\Bundle;

/**
 * Unit test for Magento\Framework\View\Asset\Bundle
 */
class BundleTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\Asset\Bundle
     */
    protected $bundle;

    /**
     * @var \Magento\Framework\Filesystem|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $filesystemMock;

    /**
     * @var \Magento\Framework\View\Asset\Bundle\ConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $bundleConfigMock;

    /**
     * @var \Magento\Framework\View\Asset\Minification|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $minificationMock;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        $this->filesystemMock = $this->getMockBuilder(\Magento\Framework\Filesystem::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->bundleConfigMock = $this->getMockBuilder(\Magento\Framework\View\Asset\Bundle\ConfigInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->minificationMock = $this->getMockBuilder(\Magento\Framework\View\Asset\Minification::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->bundle = new Bundle(
            $this->filesystemMock,
            $this->bundleConfigMock,
            $this->minificationMock
        );
    }

    /**
     * @return void
     * @covers \Magento\Framework\View\Asset\Bundle::getAssetKey
     * @covers \Magento\Framework\View\Asset\Bundle::save
     */
    public function testMinSuffix()
    {
        $this->minificationMock
            ->expects($this->any())
            ->method('addMinifiedSign')
            ->withConsecutive(
                ['onefile.js'],
                ['onefile.js'],
                ['path-to-theme/js/bundle/bundle0.js']
            )
            ->willReturnOnConsecutiveCalls(
                'onefile.min.js',
                'onefile.min.js',
                'path-to-theme/js/bundle/bundle0.min.js'
            );

        $contextMock = $this->getMockBuilder(\Magento\Framework\View\Asset\File\FallbackContext::class)
            ->disableOriginalConstructor()
            ->getMock();
        $contextMock
            ->expects($this->any())
            ->method('getAreaCode')
            ->willReturn('area');
        $contextMock
            ->expects($this->any())
            ->method('getThemePath')
            ->willReturn('theme-path');
        $contextMock
            ->expects($this->any())
            ->method('getLocale')
            ->willReturn('locale');
        $contextMock
            ->expects($this->any())
            ->method('getPath')
            ->willReturn('path-to-theme');

        $assetMock = $this->getMockBuilder(\Magento\Framework\View\Asset\LocalInterface::class)
            ->setMethods(['getContentType', 'getContext'])
            ->getMockForAbstractClass();
        $assetMock
            ->expects($this->any())
            ->method('getContext')
            ->willReturn($contextMock);
        $assetMock
            ->expects($this->any())
            ->method('getContentType')
            ->willReturn('js');
        $assetMock
            ->expects($this->any())
            ->method('getFilePath')
            ->willReturn('onefile.js');

        $writeMock = $this->getMockBuilder(\Magento\Framework\Filesystem\Directory\WriteInterface::class)
            ->getMockForAbstractClass();
        $writeMock
            ->expects($this->once())
            ->method('delete')
            ->with('path-to-theme' . DIRECTORY_SEPARATOR . \Magento\Framework\View\Asset\Bundle\Manager::BUNDLE_JS_DIR);
        $writeMock
            ->expects($this->once())
            ->method('writeFile')
            ->with('path-to-theme/js/bundle/bundle0.min.js', $this->stringContains('onefile.min.js'));

        $this->filesystemMock
            ->expects($this->any())
            ->method('getDirectoryWrite')
            ->willReturn($writeMock);

        $this->bundle->addAsset($assetMock);
        $this->bundle->flush();
    }
}
