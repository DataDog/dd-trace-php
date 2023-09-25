<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Theme\Test\Unit\Block\Html\Header;

class LogoTest extends \PHPUnit\Framework\TestCase
{
    /**
     * cover \Magento\Theme\Block\Html\Header\Logo::getLogoSrc
     */
    public function testGetLogoSrc()
    {
        $filesystem = $this->createMock(\Magento\Framework\Filesystem::class);
        $mediaDirectory = $this->createMock(\Magento\Framework\Filesystem\Directory\Read::class);
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);

        $urlBuilder = $this->createMock(\Magento\Framework\UrlInterface::class);

        $scopeConfig->expects($this->once())->method('getValue')->willReturn('default/image.gif');
        $urlBuilder->expects(
            $this->once()
        )->method(
            'getBaseUrl'
        )->willReturn(
            'http://localhost/pub/media/'
        );
        $mediaDirectory->expects($this->any())->method('isFile')->willReturn(true);

        $filesystem->expects($this->any())->method('getDirectoryRead')->willReturn($mediaDirectory);
        $helper = $this->createPartialMock(\Magento\MediaStorage\Helper\File\Storage\Database::class, ['checkDbUsage']);
        $helper->expects($this->once())->method('checkDbUsage')->willReturn(false);

        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $arguments = [
            'scopeConfig' => $scopeConfig,
            'urlBuilder' => $urlBuilder,
            'fileStorageHelper' => $helper,
            'filesystem' => $filesystem,
        ];
        $block = $objectManager->getObject(\Magento\Theme\Block\Html\Header\Logo::class, $arguments);

        $this->assertEquals('http://localhost/pub/media/logo/default/image.gif', $block->getLogoSrc());
    }

    /**
     * cover \Magento\Theme\Block\Html\Header\Logo::getLogoHeight
     */
    public function testGetLogoHeight()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())->method('getValue')->willReturn(null);

        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $arguments = [
            'scopeConfig' => $scopeConfig,
        ];
        $block = $objectManager->getObject(\Magento\Theme\Block\Html\Header\Logo::class, $arguments);

        $this->assertEquals(0, $block->getLogoHeight());
    }

    /**
     * @covers \Magento\Theme\Block\Html\Header\Logo::getLogoWidth
     */
    public function testGetLogoWidth()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())->method('getValue')->willReturn('170');

        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $arguments = [
            'scopeConfig' => $scopeConfig,
        ];
        $block = $objectManager->getObject(\Magento\Theme\Block\Html\Header\Logo::class, $arguments);

        $this->assertEquals('170', $block->getLogoHeight());
    }
}
