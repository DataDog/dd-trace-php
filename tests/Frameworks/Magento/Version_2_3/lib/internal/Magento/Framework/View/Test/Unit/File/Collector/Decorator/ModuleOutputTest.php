<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\View\Test\Unit\File\Collector\Decorator;

class ModuleOutputTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\File\Collector\Decorator\ModuleOutput
     */
    private $_model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $_fileSource;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $_moduleManager;

    protected function setUp(): void
    {
        $this->_fileSource = $this->getMockForAbstractClass(\Magento\Framework\View\File\CollectorInterface::class);
        $this->_moduleManager = $this->createMock(\Magento\Framework\Module\Manager::class);
        $this->_moduleManager
            ->expects($this->any())
            ->method('isOutputEnabled')
            ->willReturnMap([
                ['Module_OutputEnabled', true],
                ['Module_OutputDisabled', false],
            ]);
        $this->_model = new \Magento\Framework\View\File\Collector\Decorator\ModuleOutput(
            $this->_fileSource,
            $this->_moduleManager
        );
    }

    public function testGetFiles()
    {
        $theme = $this->getMockForAbstractClass(\Magento\Framework\View\Design\ThemeInterface::class);
        $fileOne = new \Magento\Framework\View\File('1.xml', 'Module_OutputEnabled');
        $fileTwo = new \Magento\Framework\View\File('2.xml', 'Module_OutputDisabled');
        $fileThree = new \Magento\Framework\View\File('3.xml', 'Module_OutputEnabled', $theme);
        $this->_fileSource
            ->expects($this->once())
            ->method('getFiles')
            ->with($theme, '*.xml')
            ->willReturn([$fileOne, $fileTwo, $fileThree]);
        $this->assertSame([$fileOne, $fileThree], $this->_model->getFiles($theme, '*.xml'));
    }
}
