<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Theme\Test\Unit\Model\Theme;

use Magento\Framework\View\Design\ThemeInterface;
use Magento\Theme\Model\Theme\Registration;

class RegistrationTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Registration
     */
    protected $model;

    /**
     * @var \Magento\Theme\Model\ResourceModel\Theme\Data\CollectionFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $collectionFactory;

    /**
     * @var \Magento\Theme\Model\Theme\Data\Collection|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $filesystemCollection;

    protected function setUp(): void
    {
        $this->collectionFactory =
            $this->getMockBuilder(\Magento\Theme\Model\ResourceModel\Theme\Data\CollectionFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->filesystemCollection = $this->getMockBuilder(\Magento\Theme\Model\Theme\Data\Collection::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->model = new Registration(
            $this->collectionFactory,
            $this->filesystemCollection
        );
    }

    /**
     * @test
     * @return void
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testRegister()
    {
        $image = 'preview.jpg';
        $themeFilePath = 'any/path';
        $parentId = 1;
        $fullPath = '/full/path';
        $theme = $this->getMockBuilder(\Magento\Framework\View\Design\ThemeInterface::class)
            ->setMethods(
                [
                    'setParentId',
                    'getId',
                    'getFullPath',
                    'getParentTheme',
                    'getCustomization',
                    'getPreviewImage',
                    'getThemeImage',
                    'setType',
                    'save',
                ]
            )
            ->getMockForAbstractClass();
        $parentTheme = $this->getMockBuilder(\Magento\Framework\View\Design\ThemeInterface::class)->getMock();
        $parentThemeFromCollectionId = 123;
        $parentThemeFromCollection = $this->getMockBuilder(\Magento\Framework\View\Design\ThemeInterface::class)
            ->setMethods(['getType', 'getId'])
            ->getMockForAbstractClass();
        $themeFromCollection = $this->getMockBuilder(\Magento\Framework\View\Design\ThemeInterface::class)
            ->setMethods(['setType', 'save', 'getParentTheme', 'getType', 'getParentId', 'setParentId'])
            ->getMockForAbstractClass();
        $collection = $this->getMockBuilder(\Magento\Theme\Model\ResourceModel\Theme\Data\Collection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $customization = $this->getMockBuilder(\Magento\Framework\View\Design\Theme\CustomizationInterface::class)
            ->getMock();
        $imageModel = $this->getMockBuilder(\Magento\Framework\View\Design\Theme\Image::class)
            ->disableOriginalConstructor()
            ->getMock();

        $theme->expects($this->once())
            ->method('save')
            ->willReturnSelf();
        $theme->expects($this->once())
            ->method('setType')
            ->willReturn(ThemeInterface::TYPE_PHYSICAL);
        $theme->expects($this->any())
            ->method('setParentId')
            ->willReturn($parentId);
        $theme->expects($this->any())
            ->method('getFullPath')
            ->willReturn($fullPath);
        $theme->expects($this->any())
            ->method('getParentTheme')
            ->willReturn($parentTheme);
        $parentTheme->expects($this->any())
            ->method('getId')
            ->willReturn($parentId);
        $this->collectionFactory->expects($this->any())
            ->method('create')
            ->willReturn($collection);
        $this->filesystemCollection->expects($this->once())
            ->method('clear');
        $this->filesystemCollection->expects($this->once())
            ->method('hasTheme')
            ->with($themeFromCollection)
            ->willReturn(false);
        $this->filesystemCollection->expects($this->once())
            ->method('getIterator')
            ->willReturn(new \ArrayIterator([$theme]));
        $collection->expects($this->once())
            ->method('getThemeByFullPath')
            ->with($fullPath)
            ->willReturn($theme);
        $theme->expects($this->once())
            ->method('getCustomization')
            ->willReturn($customization);
        $customization->expects($this->once())
            ->method('getThemeFilesPath')
            ->willReturn($themeFilePath);
        $theme->expects($this->any())
            ->method('getPreviewImage')
            ->willReturn($image);
        $theme->expects($this->once())
            ->method('getThemeImage')
            ->willReturn($imageModel);
        $imageModel->expects($this->once())
            ->method('createPreviewImage')
            ->with($themeFilePath . '/' . $image)
            ->willReturnSelf();
        $collection->expects($this->once())
            ->method('addTypeFilter')
            ->with(ThemeInterface::TYPE_PHYSICAL)
            ->willReturnSelf();
        $collection->expects($this->any())
            ->method('getIterator')
            ->willReturn(new \ArrayIterator([$themeFromCollection]));
        $collection->expects($this->any())
            ->method('addTypeRelationFilter')
            ->willReturnSelf();
        $themeFromCollection->expects($this->once())
            ->method('setType')
            ->with(ThemeInterface::TYPE_VIRTUAL)
            ->willReturnSelf();
        $themeFromCollection->expects($this->any())
            ->method('save')
            ->willReturnSelf();
        $themeFromCollection->expects($this->any())
            ->method('getParentTheme')
            ->willReturn($parentThemeFromCollection);
        $themeFromCollection->expects($this->any())
            ->method('getType')
            ->willReturn(ThemeInterface::TYPE_STAGING);
        $themeFromCollection->expects($this->any())
            ->method('getParentId')
            ->willReturn(null);
        $themeFromCollection->expects($this->any())
            ->method('setParentId')
            ->with($parentThemeFromCollectionId)
            ->willReturnSelf();
        $parentThemeFromCollection->expects($this->any())
            ->method('getType')
            ->willReturn(ThemeInterface::TYPE_VIRTUAL);
        $parentThemeFromCollection->expects($this->any())
            ->method('getId')
            ->willReturn($parentThemeFromCollectionId);

        $this->assertInstanceOf(get_class($this->model), $this->model->register());
    }
}
