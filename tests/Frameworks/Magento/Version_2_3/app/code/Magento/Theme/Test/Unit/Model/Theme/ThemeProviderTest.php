<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Theme\Test\Unit\Model\Theme;

use Magento\Framework\App\Area;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Framework\View\Design\ThemeInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ThemeProviderTest extends \PHPUnit\Framework\TestCase
{
    /** Theme path used by tests */
    const THEME_PATH = 'frontend/Magento/luma';

    /** Theme ID used by tests */
    const THEME_ID = 755;

    /** @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager */
    private $objectManager;

    /** @var \Magento\Theme\Model\ResourceModel\Theme\CollectionFactory|\PHPUnit\Framework\MockObject\MockObject */
    private $collectionFactory;

    /** @var \Magento\Theme\Model\ThemeFactory|\PHPUnit\Framework\MockObject\MockObject  */
    private $themeFactory;

    /** @var \Magento\Framework\App\CacheInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $cache;

    /** @var \Magento\Framework\Serialize\Serializer\Json|\PHPUnit\Framework\MockObject\MockObject */
    private $serializer;

    /** @var \Magento\Theme\Model\Theme\ThemeProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $themeProvider;

    /** @var \Magento\Theme\Model\Theme|\PHPUnit\Framework\MockObject\MockObject */
    private $theme;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManagerHelper($this);
        $this->collectionFactory = $this->createPartialMock(
            \Magento\Theme\Model\ResourceModel\Theme\CollectionFactory::class,
            ['create']
        );
        $this->themeFactory = $this->createPartialMock(\Magento\Theme\Model\ThemeFactory::class, ['create']);
        $this->cache = $this->getMockBuilder(\Magento\Framework\App\CacheInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->serializer = $this->createMock(\Magento\Framework\Serialize\Serializer\Json::class);
        $this->themeProvider = $this->objectManager->getObject(
            \Magento\Theme\Model\Theme\ThemeProvider::class,
            [
                'collectionFactory' => $this->collectionFactory,
                'themeFactory' => $this->themeFactory,
                'cache' => $this->cache,
                'serializer' => $this->serializer
            ]
        );
        $this->theme = $this->createMock(\Magento\Theme\Model\Theme::class);
    }

    public function testGetByFullPath()
    {
        $themeArray = ['theme_data' => 'theme_data'];
        $this->theme->expects($this->exactly(2))
            ->method('getId')
            ->willReturn(self::THEME_ID);
        $this->theme->expects($this->exactly(2))
            ->method('toArray')
            ->willReturn($themeArray);

        $collectionMock = $this->createMock(\Magento\Theme\Model\ResourceModel\Theme\Collection::class);
        $collectionMock->expects($this->once())
            ->method('getThemeByFullPath')
            ->with(self::THEME_PATH)
            ->willReturn($this->theme);
        $this->collectionFactory->expects($this->once())
            ->method('create')
            ->willReturn($collectionMock);
        $this->serializer->expects($this->exactly(2))
            ->method('serialize')
            ->with($themeArray)
            ->willReturn('serialized theme');

        $deploymentConfig = $this->getMockBuilder(\Magento\Framework\App\DeploymentConfig::class)
            ->disableOriginalConstructor()
            ->getMock();
        $deploymentConfig->expects($this->once())
            ->method('isDbAvailable')
            ->willReturn(true);

        $objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $objectManagerMock->expects($this->any())
            ->method('get')
            ->willReturnMap([
                [\Magento\Framework\App\DeploymentConfig::class, $deploymentConfig],
            ]);
        \Magento\Framework\App\ObjectManager::setInstance($objectManagerMock);

        $this->assertSame(
            $this->theme,
            $this->themeProvider->getThemeByFullPath(self::THEME_PATH),
            'Unable to load Theme'
        );
        $this->assertSame(
            $this->theme,
            $this->themeProvider->getThemeByFullPath(self::THEME_PATH),
            'Unable to load Theme from object cache'
        );
    }

    public function testGetByFullPathWithCache()
    {
        $deploymentConfig = $this->getMockBuilder(\Magento\Framework\App\DeploymentConfig::class)
            ->disableOriginalConstructor()
            ->getMock();
        $deploymentConfig->expects($this->once())
            ->method('isDbAvailable')
            ->willReturn(true);

        $objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $objectManagerMock->expects($this->any())
            ->method('get')
            ->willReturnMap([
                [\Magento\Framework\App\DeploymentConfig::class, $deploymentConfig],
            ]);
        \Magento\Framework\App\ObjectManager::setInstance($objectManagerMock);

        $serializedTheme = '{"theme_data":"theme_data"}';
        $themeArray = ['theme_data' => 'theme_data'];
        $this->theme->expects($this->once())
            ->method('populateFromArray')
            ->with($themeArray)
            ->willReturnSelf();
        $this->themeFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->theme);

        $this->serializer->expects($this->once())
            ->method('unserialize')
            ->with($serializedTheme)
            ->willReturn($themeArray);

        $this->cache->expects($this->once())
            ->method('load')
            ->with('theme' . self::THEME_PATH)
            ->willReturn($serializedTheme);

        $this->assertSame(
            $this->theme,
            $this->themeProvider->getThemeByFullPath(self::THEME_PATH),
            'Unable to load Theme from application cache'
        );
        $this->assertSame(
            $this->theme,
            $this->themeProvider->getThemeByFullPath(self::THEME_PATH),
            'Unable to load Theme from object cache'
        );
    }

    public function testGetById()
    {
        $themeArray = ['theme_data' => 'theme_data'];
        $this->theme->expects($this->once())
            ->method('load')
            ->with(self::THEME_ID)
            ->willReturnSelf();
        $this->theme->expects($this->once())
            ->method('getId')
            ->willReturn(self::THEME_ID);
        $this->theme->expects($this->once())
            ->method('toArray')
            ->willReturn($themeArray);

        $this->themeFactory->expects($this->once())->method('create')->willReturn($this->theme);
        $this->cache->expects($this->once())
            ->method('load')
            ->with('theme-by-id-' . self::THEME_ID)
            ->willReturn(false);
        $this->serializer->expects($this->once())
            ->method('serialize')
            ->with($themeArray)
            ->willReturn('{"theme_data":"theme_data"}');

        $this->assertSame(
            $this->theme,
            $this->themeProvider->getThemeById(self::THEME_ID),
            'Unable to load Theme'
        );
        $this->assertSame(
            $this->theme,
            $this->themeProvider->getThemeById(self::THEME_ID),
            'Unable to load Theme from object cache'
        );
    }

    public function testGetByIdWithCache()
    {
        $serializedTheme = '{"theme_data":"theme_data"}';
        $themeArray = ['theme_data' => 'theme_data'];
        $this->theme->expects($this->once())
            ->method('populateFromArray')
            ->with($themeArray)
            ->willReturnSelf();
        $this->cache->expects($this->once())
            ->method('load')
            ->with('theme-by-id-' . self::THEME_ID)
            ->willReturn($serializedTheme);
        $this->serializer->expects($this->once())
            ->method('unserialize')
            ->with($serializedTheme)
            ->willReturn($themeArray);
        $this->themeFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->theme);

        $this->assertSame(
            $this->theme,
            $this->themeProvider->getThemeById(self::THEME_ID),
            'Unable to load Theme from application cache'
        );
        $this->assertSame(
            $this->theme,
            $this->themeProvider->getThemeById(self::THEME_ID),
            'Unable to load Theme from object cache'
        );
    }

    public function testGetThemeCustomizations()
    {
        $collection = $this->getMockBuilder(\Magento\Theme\Model\ResourceModel\Theme\Collection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $collection->expects($this->once())
            ->method('addAreaFilter')
            ->with(Area::AREA_FRONTEND)
            ->willReturnSelf();
        $collection->expects($this->once())
            ->method('addTypeFilter')
            ->with(ThemeInterface::TYPE_VIRTUAL)
            ->willReturnSelf();
        $this->collectionFactory->expects($this->once())
            ->method('create')
            ->willReturn($collection);

        $this->assertInstanceOf(get_class($collection), $this->themeProvider->getThemeCustomizations());
    }
}
