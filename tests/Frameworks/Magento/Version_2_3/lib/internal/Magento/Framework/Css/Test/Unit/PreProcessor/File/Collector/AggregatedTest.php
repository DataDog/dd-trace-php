<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Css\Test\Unit\PreProcessor\File\Collector;

use \Magento\Framework\Css\PreProcessor\File\Collector\Aggregated;

/**
 * Tests Aggregate
 */
class AggregatedTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\File\FileList\Factory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $fileListFactoryMock;

    /**
     * @var \Magento\Framework\View\File\FileList|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $fileListMock;

    /**
     * @var \Magento\Framework\View\File\CollectorInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $libraryFilesMock;

    /**
     * @var \Magento\Framework\View\File\CollectorInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $baseFilesMock;

    /**
     * @var \Magento\Framework\View\File\CollectorInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $overriddenBaseFilesMock;

    /**
     * @var \Magento\Framework\View\Design\ThemeInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $themeMock;

    /**
     * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $loggerMock;

    /**
     * Setup tests
     * @return void
     */
    protected function setup(): void
    {
        $this->fileListFactoryMock = $this->getMockBuilder(\Magento\Framework\View\File\FileList\Factory::class)
            ->disableOriginalConstructor()->getMock();
        $this->fileListMock = $this->getMockBuilder(\Magento\Framework\View\File\FileList::class)
            ->disableOriginalConstructor()->getMock();
        $this->fileListFactoryMock->expects($this->any())->method('create')
            ->willReturn($this->fileListMock);
        $this->libraryFilesMock = $this->getMockBuilder(\Magento\Framework\View\File\CollectorInterface::class)
            ->getMock();
        $this->loggerMock = $this->getMockBuilder(\Psr\Log\LoggerInterface::class)
            ->getMock();

        $this->baseFilesMock = $this->getMockBuilder(\Magento\Framework\View\File\CollectorInterface::class)->getMock();
        $this->overriddenBaseFilesMock = $this->getMockBuilder(\Magento\Framework\View\File\CollectorInterface::class)
            ->getMock();
        $this->themeMock = $this->getMockBuilder(\Magento\Framework\View\Design\ThemeInterface::class)->getMock();
    }

    public function testGetFilesEmpty()
    {
        $this->libraryFilesMock->expects($this->any())->method('getFiles')->willReturn([]);
        $this->baseFilesMock->expects($this->any())->method('getFiles')->willReturn([]);
        $this->overriddenBaseFilesMock->expects($this->any())->method('getFiles')->willReturn([]);

        $aggregated = new Aggregated(
            $this->fileListFactoryMock,
            $this->libraryFilesMock,
            $this->baseFilesMock,
            $this->overriddenBaseFilesMock,
            $this->loggerMock
        );

        $this->themeMock->expects($this->any())->method('getInheritedThemes')->willReturn([]);
        $this->themeMock->expects($this->any())->method('getCode')->willReturn('theme_code');

        $this->loggerMock->expects($this->once())
            ->method('notice')
            ->with('magento_import returns empty result by path * for theme theme_code', []);

        $aggregated->getFiles($this->themeMock, '*');
    }

    /**
     *
     * @dataProvider getFilesDataProvider
     *
     * @param array $libraryFiles Files in lib directory
     * @param array $baseFiles Files in base directory
     * @param array $themeFiles Files in theme
     * *
     * @return void
     */
    public function testGetFiles($libraryFiles, $baseFiles, $themeFiles)
    {
        $this->fileListMock->expects($this->at(0))->method('add')->with($this->equalTo($libraryFiles));
        $this->fileListMock->expects($this->at(1))->method('add')->with($this->equalTo($baseFiles));
        $this->fileListMock->expects($this->any())->method('getAll')->willReturn(['returnedFile']);

        $subPath = '*';
        $this->libraryFilesMock->expects($this->atLeastOnce())
            ->method('getFiles')
            ->with($this->themeMock, $subPath)
            ->willReturn($libraryFiles);

        $this->baseFilesMock->expects($this->atLeastOnce())
            ->method('getFiles')
            ->with($this->themeMock, $subPath)
            ->willReturn($baseFiles);

        $this->overriddenBaseFilesMock->expects($this->any())
            ->method('getFiles')
            ->willReturn($themeFiles);

        $aggregated = new Aggregated(
            $this->fileListFactoryMock,
            $this->libraryFilesMock,
            $this->baseFilesMock,
            $this->overriddenBaseFilesMock,
            $this->loggerMock
        );

        $inheritedThemeMock = $this->getMockBuilder(\Magento\Framework\View\Design\ThemeInterface::class)->getMock();
        $this->themeMock->expects($this->any())->method('getInheritedThemes')
            ->willReturn([$inheritedThemeMock]);

        $this->assertEquals(['returnedFile'], $aggregated->getFiles($this->themeMock, $subPath));
    }

    /**
     * Provides test data for testGetFiles()
     *
     * @return array
     */
    public function getFilesDataProvider()
    {
        return [
            'all files' => [['file1'], ['file2'], ['file3']],
            'no library' => [[], ['file1', 'file2'], ['file3']],
        ];
    }
}
