<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Theme\Test\Unit\Model;

use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CopyServiceTest extends \PHPUnit\Framework\TestCase
{
    /**#@+
     * @var \Magento\Theme\Model\CopyService
     */
    protected $object;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $fileFactory;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $filesystem;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $sourceTheme;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $targetTheme;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $link;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $linkCollection;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $update;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $updateCollection;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $updateFactory;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $customizationPath;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject[]
     */
    protected $targetFiles = [];

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject[]
     */
    protected $sourceFiles = [];

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $dirWriteMock;

    /**
     * @return void
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function setUp(): void
    {
        $sourceFileOne = $this->createPartialMock(\Magento\Theme\Model\Theme\File::class, ['__wakeup', 'delete']);
        $sourceFileOne->setData(
            [
                'file_path' => 'fixture_file_path_one',
                'file_type' => 'fixture_file_type_one',
                'content' => 'fixture_content_one',
                'sort_order' => 10,
            ]
        );
        $sourceFileTwo = $this->createPartialMock(\Magento\Theme\Model\Theme\File::class, ['__wakeup', 'delete']);
        $sourceFileTwo->setData(
            [
                'file_path' => 'fixture_file_path_two',
                'file_type' => 'fixture_file_type_two',
                'content' => 'fixture_content_two',
                'sort_order' => 20,
            ]
        );
        $this->sourceFiles = [$sourceFileOne, $sourceFileTwo];
        $this->sourceTheme = $this->createPartialMock(
            \Magento\Theme\Model\Theme::class,
            ['__wakeup', 'getCustomization']
        );

        $this->targetFiles = [
            $this->createPartialMock(\Magento\Theme\Model\Theme\File::class, ['__wakeup', 'delete']),
            $this->createPartialMock(\Magento\Theme\Model\Theme\File::class, ['__wakeup', 'delete']),
        ];
        $this->targetTheme = $this->createPartialMock(
            \Magento\Theme\Model\Theme::class,
            ['__wakeup', 'getCustomization']
        );
        $this->targetTheme->setId(123);

        $this->customizationPath = $this->createMock(\Magento\Framework\View\Design\Theme\Customization\Path::class);

        $this->fileFactory = $this->createPartialMock(
            \Magento\Framework\View\Design\Theme\FileFactory::class,
            ['create']
        );
        $this->filesystem =
            $this->createPartialMock(\Magento\Framework\Filesystem::class, ['getDirectoryWrite']);
        $this->dirWriteMock = $this->createPartialMock(
            \Magento\Framework\Filesystem\Directory\Write::class,
            ['isDirectory', 'search', 'copy', 'delete', 'read', 'copyFile', 'isExist']
        );
        $this->filesystem->expects(
            $this->any()
        )->method(
            'getDirectoryWrite'
        )->with(
            DirectoryList::MEDIA
        )->willReturn(
            $this->dirWriteMock
        );

        /* Init \Magento\Widget\Model\ResourceModel\Layout\Update\Collection model  */
        $this->updateFactory = $this->createPartialMock(\Magento\Widget\Model\Layout\UpdateFactory::class, ['create']);
        $this->update = $this->createPartialMock(
            \Magento\Widget\Model\Layout\Update::class,
            ['__wakeup', 'getCollection']
        );
        $this->updateFactory->expects($this->at(0))->method('create')->willReturn($this->update);
        $this->updateCollection = $this->createPartialMock(
            \Magento\Widget\Model\ResourceModel\Layout\Update\Collection::class,
            ['addThemeFilter', 'delete', 'getIterator']
        );
        $this->update->expects(
            $this->any()
        )->method(
            'getCollection'
        )->willReturn(
            $this->updateCollection
        );

        /* Init Link an Link_Collection model */
        $this->link = $this->createPartialMock(\Magento\Widget\Model\Layout\Link::class, ['__wakeup', 'getCollection']);
        $this->linkCollection = $this->createPartialMock(
            \Magento\Widget\Model\ResourceModel\Layout\Link\Collection::class,
            ['addThemeFilter', 'getIterator', 'addFieldToFilter']
        );
        $this->link->expects($this->any())->method('getCollection')->willReturn($this->linkCollection);

        $eventManager = $this->createPartialMock(\Magento\Framework\Event\ManagerInterface::class, ['dispatch']);

        $this->object = new \Magento\Theme\Model\CopyService(
            $this->filesystem,
            $this->fileFactory,
            $this->link,
            $this->updateFactory,
            $eventManager,
            $this->customizationPath
        );
    }

    protected function tearDown(): void
    {
        $this->object = null;
        $this->filesystem = null;
        $this->fileFactory = null;
        $this->sourceTheme = null;
        $this->targetTheme = null;
        $this->link = null;
        $this->linkCollection = null;
        $this->updateCollection = null;
        $this->updateFactory = null;
        $this->sourceFiles = [];
        $this->targetFiles = [];
    }

    /**
     * cover \Magento\Theme\Model\CopyService::_copyLayoutCustomization
     */
    public function testCopyLayoutUpdates()
    {
        $customization = $this->createPartialMock(
            \Magento\Framework\View\Design\Theme\Customization::class,
            ['getFiles']
        );
        $customization->expects($this->atLeastOnce())->method('getFiles')->willReturn([]);
        $this->sourceTheme->expects(
            $this->once()
        )->method(
            'getCustomization'
        )->willReturn(
            $customization
        );
        $this->targetTheme->expects(
            $this->once()
        )->method(
            'getCustomization'
        )->willReturn(
            $customization
        );

        $this->updateCollection->expects($this->once())->method('delete');
        $this->linkCollection->expects($this->once())->method('addThemeFilter');

        $targetLinkOne = $this->createPartialMock(
            \Magento\Widget\Model\Layout\Link::class,
            ['__wakeup', 'setId', 'setThemeId', 'save', 'setLayoutUpdateId']
        );
        $targetLinkOne->setData(['id' => 1, 'layout_update_id' => 1]);
        $targetLinkTwo = $this->createPartialMock(
            \Magento\Widget\Model\Layout\Link::class,
            ['__wakeup', 'setId', 'setThemeId', 'save', 'setLayoutUpdateId']
        );
        $targetLinkTwo->setData(['id' => 2, 'layout_update_id' => 2]);

        $targetLinkOne->expects($this->at(0))->method('setThemeId')->with(123);
        $targetLinkOne->expects($this->at(1))->method('setLayoutUpdateId')->with(1);
        $targetLinkOne->expects($this->at(2))->method('setId')->with(null);
        $targetLinkOne->expects($this->at(3))->method('save');

        $targetLinkTwo->expects($this->at(0))->method('setThemeId')->with(123);
        $targetLinkTwo->expects($this->at(1))->method('setLayoutUpdateId')->with(2);
        $targetLinkTwo->expects($this->at(2))->method('setId')->with(null);
        $targetLinkTwo->expects($this->at(3))->method('save');

        $linkReturnValues = $this->onConsecutiveCalls(new \ArrayIterator([$targetLinkOne, $targetLinkTwo]));
        $this->linkCollection->expects($this->any())->method('getIterator')->will($linkReturnValues);

        $targetUpdateOne = $this->createPartialMock(
            \Magento\Widget\Model\Layout\Update::class,
            ['__wakeup', 'setId', 'load', 'save']
        );
        $targetUpdateOne->setData(['id' => 1]);
        $targetUpdateTwo = $this->createPartialMock(
            \Magento\Widget\Model\Layout\Update::class,
            ['__wakeup', 'setId', 'load', 'save']
        );
        $targetUpdateTwo->setData(['id' => 2]);
        $updateReturnValues = $this->onConsecutiveCalls($this->update, $targetUpdateOne, $targetUpdateTwo);
        $this->updateFactory->expects($this->any())->method('create')->will($updateReturnValues);

        $this->object->copy($this->sourceTheme, $this->targetTheme);
    }

    /**
     * cover \Magento\Theme\Model\CopyService::_copyDatabaseCustomization
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testCopyDatabaseCustomization()
    {
        $sourceCustom = $this->createPartialMock(
            \Magento\Framework\View\Design\Theme\Customization::class,
            ['getFiles']
        );
        $sourceCustom->expects(
            $this->atLeastOnce()
        )->method(
            'getFiles'
        )->willReturn(
            $this->sourceFiles
        );
        $this->sourceTheme->expects(
            $this->once()
        )->method(
            'getCustomization'
        )->willReturn(
            $sourceCustom
        );
        $targetCustom = $this->createPartialMock(
            \Magento\Framework\View\Design\Theme\Customization::class,
            ['getFiles']
        );
        $targetCustom->expects(
            $this->atLeastOnce()
        )->method(
            'getFiles'
        )->willReturn(
            $this->targetFiles
        );
        $this->targetTheme->expects(
            $this->once()
        )->method(
            'getCustomization'
        )->willReturn(
            $targetCustom
        );

        $this->linkCollection->expects(
            $this->any()
        )->method(
            'addFieldToFilter'
        )->willReturn(
            $this->linkCollection
        );
        $this->linkCollection->expects(
            $this->any()
        )->method(
            'getIterator'
        )->willReturn(
            new \ArrayIterator([])
        );

        foreach ($this->targetFiles as $targetFile) {
            $targetFile->expects($this->once())->method('delete');
        }

        $newFileOne = $this->createPartialMock(\Magento\Theme\Model\Theme\File::class, ['__wakeup', 'setData', 'save']);
        $newFileTwo = $this->createPartialMock(\Magento\Theme\Model\Theme\File::class, ['__wakeup', 'setData', 'save']);
        $newFileOne->expects(
            $this->at(0)
        )->method(
            'setData'
        )->with(
            [
                'theme_id' => 123,
                'file_path' => 'fixture_file_path_one',
                'file_type' => 'fixture_file_type_one',
                'content' => 'fixture_content_one',
                'sort_order' => 10,
            ]
        );
        $newFileOne->expects($this->at(1))->method('save');
        $newFileTwo->expects(
            $this->at(0)
        )->method(
            'setData'
        )->with(
            [
                'theme_id' => 123,
                'file_path' => 'fixture_file_path_two',
                'file_type' => 'fixture_file_type_two',
                'content' => 'fixture_content_two',
                'sort_order' => 20,
            ]
        );
        $newFileTwo->expects($this->at(1))->method('save');
        $this->fileFactory->expects(
            $this->any()
        )->method(
            'create'
        )->with(
            []
        )->will(
            $this->onConsecutiveCalls($newFileOne, $newFileTwo)
        );

        $this->object->copy($this->sourceTheme, $this->targetTheme);
    }

    /**
     * cover \Magento\Theme\Model\CopyService::_copyFilesystemCustomization
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testCopyFilesystemCustomization()
    {
        $customization = $this->createPartialMock(
            \Magento\Framework\View\Design\Theme\Customization::class,
            ['getFiles']
        );
        $customization->expects($this->atLeastOnce())->method('getFiles')->willReturn([]);
        $this->sourceTheme->expects(
            $this->once()
        )->method(
            'getCustomization'
        )->willReturn(
            $customization
        );
        $this->targetTheme->expects(
            $this->once()
        )->method(
            'getCustomization'
        )->willReturn(
            $customization
        );

        $this->linkCollection->expects(
            $this->any()
        )->method(
            'addFieldToFilter'
        )->willReturn(
            $this->linkCollection
        );
        $this->linkCollection->expects(
            $this->any()
        )->method(
            'getIterator'
        )->willReturn(
            new \ArrayIterator([])
        );

        $this->customizationPath->expects(
            $this->at(0)
        )->method(
            'getCustomizationPath'
        )->willReturn(
            'source/path'
        );

        $this->customizationPath->expects(
            $this->at(1)
        )->method(
            'getCustomizationPath'
        )->willReturn(
            'target/path'
        );

        $this->dirWriteMock->expects(
            $this->any()
        )->method(
            'isDirectory'
        )->willReturnMap(
            [['source/path', true], ['source/path/subdir', true]]
        );

        $this->dirWriteMock->expects(
            $this->any()
        )->method(
            'isExist'
        )->willReturnMap(
            
                [
                    ['target/path', true]
                ]
            
        );

        $this->dirWriteMock->expects(
            $this->any()
        )->method(
            'read'
        )->willReturnMap(
            
                [
                    ['target/path', ['target/path/subdir']],
                    ['source/path', ['source/path/subdir']],
                    ['source/path/subdir', ['source/path/subdir/file_one.jpg', 'source/path/subdir/file_two.png']],
                ]
            
        );

        $expectedCopyEvents = [
            ['source/path/subdir/file_one.jpg', 'target/path/subdir/file_one.jpg', null],
            ['source/path/subdir/file_two.png', 'target/path/subdir/file_two.png', null],
        ];
        $actualCopyEvents = [];
        $recordCopyEvent = function () use (&$actualCopyEvents) {
            $actualCopyEvents[] = func_get_args();
        };
        $this->dirWriteMock->expects($this->any())->method('copyFile')->willReturnCallback($recordCopyEvent);

        $this->object->copy($this->sourceTheme, $this->targetTheme);

        $this->assertEquals($expectedCopyEvents, $actualCopyEvents);
    }
}
