<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Test of customization path model
 */
namespace Magento\Framework\View\Test\Unit\Design\Theme\Customization;

use Magento\Framework\Component\ComponentRegistrar;

class PathTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\Design\Theme\Customization\Path
     */
    private $_model;

    /**
     * @var \Magento\Theme\Model\Theme|\PHPUnit\Framework\MockObject\MockObject
     */
    private $_theme;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $_directory;

    /**
     * @var \Magento\Framework\Component\ComponentRegistrarInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $componentRegistrar;

    protected function setUp(): void
    {
        $this->_theme = $this->getMockForAbstractClass(\Magento\Framework\View\Design\ThemeInterface::class);
        /** @var \Magento\Framework\Filesystem|\PHPUnit\Framework\MockObject\MockObject $filesystem */
        $filesystem = $this->createMock(\Magento\Framework\Filesystem::class);
        $this->_directory = $this->createMock(\Magento\Framework\Filesystem\Directory\Read::class);
        $filesystem->expects($this->any())->method('getDirectoryRead')->willReturn($this->_directory);
        $this->_directory->expects($this->any())->method('getAbsolutePath')->willReturnArgument(0);
        $this->componentRegistrar = $this->getMockForAbstractClass(
            \Magento\Framework\Component\ComponentRegistrarInterface::class
        );
        $this->_model = new \Magento\Framework\View\Design\Theme\Customization\Path(
            $filesystem,
            $this->componentRegistrar
        );
    }

    protected function tearDown(): void
    {
        $this->_theme = null;
        $this->_directory = null;
        $this->_model = null;
    }

    /**
     * @covers \Magento\Framework\View\Design\Theme\Customization\Path::__construct
     * @covers \Magento\Framework\View\Design\Theme\Customization\Path::getCustomizationPath
     */
    public function testGetCustomizationPath()
    {
        $expectedPath = implode('/', [\Magento\Framework\View\Design\Theme\Customization\Path::DIR_NAME, '123']);
        $this->_theme->expects($this->exactly(2))
            ->method('getId')
            ->willReturn(123);
        $this->assertEquals($expectedPath, $this->_model->getCustomizationPath($this->_theme));
    }

    /**
     * @covers \Magento\Framework\View\Design\Theme\Customization\Path::__construct
     * @covers \Magento\Framework\View\Design\Theme\Customization\Path::getCustomizationPath
     */
    public function testGetCustomizationPathNoId()
    {
        $this->_theme->expects($this->once())
            ->method('getId')
            ->willReturn(null);
        $this->assertNull($this->_model->getCustomizationPath($this->_theme));
    }

    /**
     * @covers \Magento\Framework\View\Design\Theme\Customization\Path::getThemeFilesPath
     */
    public function testGetThemeFilesPath()
    {
        $this->_theme->expects($this->any())
            ->method('getFullPath')
            ->willReturn('frontend/Magento/theme');
        $expectedPath = '/fill/theme/path';
        $this->componentRegistrar->expects($this->once())
            ->method('getPath')
            ->with(ComponentRegistrar::THEME, 'frontend/Magento/theme')
            ->willReturn($expectedPath);
        $this->assertEquals($expectedPath, $this->_model->getThemeFilesPath($this->_theme));
    }

    /**
     * @covers \Magento\Framework\View\Design\Theme\Customization\Path::getThemeFilesPath
     */
    public function testGetThemeFilesPathNoPath()
    {
        $this->_theme->expects($this->any())
            ->method('getFullPath')
            ->willReturn(null);
        $this->componentRegistrar->expects($this->never())
            ->method('getPath');
        $this->assertNull($this->_model->getCustomizationPath($this->_theme));
    }

    /**
     * @covers \Magento\Framework\View\Design\Theme\Customization\Path::getCustomViewConfigPath
     */
    public function testGetCustomViewConfigPath()
    {
        $expectedPath = implode(
            '/',
            [
                \Magento\Framework\View\Design\Theme\Customization\Path::DIR_NAME,
                '123',
                \Magento\Framework\View\ConfigInterface::CONFIG_FILE_NAME
            ]
        );
        $this->_theme->expects($this->exactly(2))
            ->method('getId')
            ->willReturn(123);
        $this->assertEquals($expectedPath, $this->_model->getCustomViewConfigPath($this->_theme));
    }

    /**
     * @covers \Magento\Framework\View\Design\Theme\Customization\Path::getCustomViewConfigPath
     */
    public function testGetCustomViewConfigPathNoId()
    {
        $this->_theme->expects($this->once())
            ->method('getId')
            ->willReturn(null);
        $this->assertNull($this->_model->getCustomViewConfigPath($this->_theme));
    }
}
