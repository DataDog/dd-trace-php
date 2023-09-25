<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\View\Test\Unit\File\Collector\Decorator;

class ModuleDependencyTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\File\Collector\Decorator\ModuleDependency
     */
    private $_model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $_fileSource;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $_moduleListMock;

    protected function setUp(): void
    {
        $this->_fileSource = $this->getMockForAbstractClass(\Magento\Framework\View\File\CollectorInterface::class);
        $this->_moduleListMock = $this->createMock(\Magento\Framework\Module\ModuleListInterface::class);
        $this->_moduleListMock->expects($this->any())
            ->method('getNames')
            ->willReturn(['Fixture_ModuleB', 'Fixture_ModuleA']);
        $this->_model = new \Magento\Framework\View\File\Collector\Decorator\ModuleDependency(
            $this->_fileSource,
            $this->_moduleListMock
        );
    }

    /**
     * @param array $fixtureFiles
     * @param array $expectedFiles
     * @param string $message
     * @dataProvider getFilesDataProvider
     */
    public function testGetFiles(array $fixtureFiles, array $expectedFiles, $message)
    {
        $theme = $this->getMockForAbstractClass(\Magento\Framework\View\Design\ThemeInterface::class);
        $this->_fileSource
            ->expects($this->once())
            ->method('getFiles')
            ->with($theme, '*.xml')
            ->willReturn($fixtureFiles);
        $this->assertSame($expectedFiles, $this->_model->getFiles($theme, '*.xml'), $message);
    }

    /**
     * @return array
     */
    public function getFilesDataProvider()
    {
        $fileOne = new \Magento\Framework\View\File('b.xml', 'Fixture_ModuleB');
        $fileTwo = new \Magento\Framework\View\File('a.xml', 'Fixture_ModuleA');
        $fileThree = new \Magento\Framework\View\File('b.xml', 'Fixture_ModuleA');

        $unknownFileOne = new \Magento\Framework\View\File('b.xml', 'Unknown_ModuleA');
        $unknownFileTwo = new \Magento\Framework\View\File('a.xml', 'Unknown_ModuleB');
        return [
            'same module' => [
                [$fileThree, $fileTwo],
                [$fileTwo, $fileThree],
                'Files belonging to the same module are expected to be sorted by file names',
            ],
            'different modules' => [
                [$fileTwo, $fileOne],
                [$fileOne, $fileTwo],
                'Files belonging to different modules are expected to be sorted by module dependencies',
            ],
            'different unknown modules' => [
                [$unknownFileTwo, $unknownFileOne],
                [$unknownFileOne, $unknownFileTwo],
                'Files belonging to different unknown modules are expected to be sorted by module names',
            ],
            'known and unknown modules' => [
                [$fileTwo, $unknownFileOne],
                [$unknownFileOne, $fileTwo],
                'Files belonging to unknown modules are expected to go before ones of known modules',
            ],
        ];
    }
}
