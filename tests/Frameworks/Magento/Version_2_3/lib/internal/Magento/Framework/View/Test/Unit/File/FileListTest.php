<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\View\Test\Unit\File;

class FileListTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\File\FileList
     */
    private $_model;

    /**
     * @var \Magento\Framework\View\File
     */
    private $_baseFile;

    /**
     * @var \Magento\Framework\View\File
     */
    private $_themeFile;

    /**
     * @var \Magento\Framework\View\File\FileList\Collator|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $collator;

    protected function setUp(): void
    {
        $this->_baseFile = $this->_createViewFile('fixture.xml', 'Fixture_TestModule');
        $this->_themeFile = $this->_createViewFile('fixture.xml', 'Fixture_TestModule', 'area/theme/path');
        $this->collator = $this->createPartialMock(\Magento\Framework\View\File\FileList\Collator::class, ['collate']);
        $this->_model = new \Magento\Framework\View\File\FileList($this->collator);
        $this->_model->add([$this->_baseFile, $this->_themeFile]);
    }

    /**
     * Return newly created theme view file with a mocked theme
     *
     * @param string $filename
     * @param string $module
     * @param string|null $themeFullPath
     * @return \PHPUnit\Framework\MockObject\MockObject|\Magento\Framework\View\Design\ThemeInterface
     */
    protected function _createViewFile($filename, $module, $themeFullPath = null)
    {
        $theme = null;
        if ($themeFullPath !== null) {
            $theme = $this->getMockForAbstractClass(\Magento\Framework\View\Design\ThemeInterface::class);
            $theme->expects($this->any())->method('getFullPath')->willReturn($themeFullPath);
        }
        return new \Magento\Framework\View\File($filename, $module, $theme);
    }

    public function testGetAll()
    {
        $this->assertSame([$this->_baseFile, $this->_themeFile], $this->_model->getAll());
    }

    public function testAddBaseFile()
    {
        $file = $this->_createViewFile('new.xml', 'Fixture_TestModule');
        $this->_model->add([$file]);
        $this->assertSame([$this->_baseFile, $this->_themeFile, $file], $this->_model->getAll());
    }

    public function testAddThemeFile()
    {
        $file = $this->_createViewFile('new.xml', 'Fixture_TestModule', 'area/theme/path');
        $this->_model->add([$file]);
        $this->assertSame([$this->_baseFile, $this->_themeFile, $file], $this->_model->getAll());
    }

    /**
     */
    public function testAddBaseFileException()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('View file \'test/fixture.xml\' is indistinguishable from the file \'fixture.xml\'');

        $file = $this->_createViewFile('test/fixture.xml', 'Fixture_TestModule');
        $this->_model->add([$file]);
    }

    /**
     */
    public function testAddThemeFileException()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('View file \'test/fixture.xml\' is indistinguishable from the file \'fixture.xml\'');

        $file = $this->_createViewFile('test/fixture.xml', 'Fixture_TestModule', 'area/theme/path');
        $this->_model->add([$file]);
    }

    public function testReplace()
    {
        $files = ['1'];
        $result = ['3'];
        $this->collator
            ->expects($this->once())
            ->method('collate')
            ->with(
                $this->equalTo($files),
                $this->equalTo(
                    [
                        $this->_baseFile->getFileIdentifier() => $this->_baseFile,
                        $this->_themeFile->getFileIdentifier() => $this->_themeFile,
                    ]
                )
            )
            ->willReturn($result);
        $this->assertNull($this->_model->replace($files));
        $this->assertSame($result, $this->_model->getAll());
    }
}
