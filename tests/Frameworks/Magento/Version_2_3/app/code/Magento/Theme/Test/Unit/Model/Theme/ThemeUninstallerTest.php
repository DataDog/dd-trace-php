<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Theme\Test\Unit\Model\Theme;

use Magento\Theme\Model\Theme\ThemeUninstaller;

class ThemeUninstallerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Theme\Model\Theme\ThemePackageInfo|\PHPUnit\Framework\MockObject\MockObject
     */
    private $themePackageInfo;

    /**
     * @var \Magento\Framework\Composer\Remove|\PHPUnit\Framework\MockObject\MockObject
     */
    private $remove;

    /**
     * @var \Magento\Theme\Model\Theme\ThemeProvider|\PHPUnit\Framework\MockObject\MockObject
     */
    private $themeProvider;

    /**
     * @var ThemeUninstaller
     */
    private $themeUninstaller;

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $output;

    protected function setUp(): void
    {
        $this->themePackageInfo = $this->createMock(\Magento\Theme\Model\Theme\ThemePackageInfo::class);
        $this->remove = $this->createMock(\Magento\Framework\Composer\Remove::class);
        $this->themeProvider = $this->createMock(\Magento\Theme\Model\Theme\ThemeProvider::class);
        $this->themeUninstaller = new ThemeUninstaller($this->themePackageInfo, $this->remove, $this->themeProvider);
        $this->output = $this->getMockForAbstractClass(
            \Symfony\Component\Console\Output\OutputInterface::class,
            [],
            '',
            false
        );
    }

    public function testUninstallRegistry()
    {
        $this->output->expects($this->atLeastOnce())->method('writeln');
        $this->themePackageInfo->expects($this->never())->method($this->anything());
        $this->remove->expects($this->never())->method($this->anything());
        $theme = $this->createMock(\Magento\Theme\Model\Theme::class);
        $theme->expects($this->exactly(3))->method('delete');
        $this->themeProvider->expects($this->exactly(3))->method('getThemeByFullPath')->willReturn($theme);
        $this->themeUninstaller->uninstallRegistry(
            $this->output,
            ['frontend/Magento/ThemeA', 'frontend/Magento/ThemeB', 'frontend/Magento/ThemeC']
        );
    }

    public function testUninstallCode()
    {
        $this->output->expects($this->atLeastOnce())->method('writeln');
        $this->themePackageInfo->expects($this->at(0))->method('getPackageName')->willReturn('packageA');
        $this->themePackageInfo->expects($this->at(1))->method('getPackageName')->willReturn('packageB');
        $this->themePackageInfo->expects($this->at(2))->method('getPackageName')->willReturn('packageC');
        $this->remove->expects($this->once())
            ->method('remove')
            ->with(['packageA', 'packageB', 'packageC'])
            ->willReturn('');
        $this->themeProvider->expects($this->never())->method($this->anything());
        $this->themeUninstaller->uninstallCode(
            $this->output,
            ['frontend/Magento/ThemeA', 'frontend/Magento/ThemeB', 'frontend/Magento/ThemeC']
        );
    }
}
