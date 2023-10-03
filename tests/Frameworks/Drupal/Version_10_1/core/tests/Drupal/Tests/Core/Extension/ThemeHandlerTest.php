<?php

/**
 * @file
 * Contains \Drupal\Tests\Core\Extension\ThemeHandlerTest.
 */

namespace Drupal\Tests\Core\Extension;

use Composer\Autoload\ClassLoader;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeHandler;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\Core\Extension\ThemeHandler
 * @group Extension
 */
class ThemeHandlerTest extends UnitTestCase {

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * The theme listing service.
   *
   * @var \Drupal\Core\Extension\ThemeExtensionList|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $themeList;

  /**
   * The tested theme handler.
   *
   * @var \Drupal\Core\Extension\ThemeHandler|\Drupal\Tests\Core\Extension\StubThemeHandler
   */
  protected $themeHandler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->getConfigFactoryStub([
      'core.extension' => [
        'module' => [],
        'theme' => [],
        'disabled' => [
          'theme' => [],
        ],
      ],
    ]);
    $this->themeList = $this->getMockBuilder(ThemeExtensionList::class)
      ->disableOriginalConstructor()
      ->getMock();
    $this->themeHandler = new StubThemeHandler($this->root, $this->configFactory, $this->themeList);

    $container = $this->createMock('Symfony\Component\DependencyInjection\ContainerInterface');
    $container->expects($this->any())
      ->method('get')
      ->with('class_loader')
      ->willReturn($this->createMock(ClassLoader::class));
    \Drupal::setContainer($container);
  }

  /**
   * Tests rebuilding the theme data.
   *
   * @see \Drupal\Core\Extension\ThemeHandler::rebuildThemeData()
   */
  public function testRebuildThemeData() {
    $this->themeList->expects($this->once())
      ->method('reset')
      ->willReturnSelf();
    $this->themeList->expects($this->once())
      ->method('getList')
      ->willReturn([
        'stark' => new Extension($this->root, 'theme', 'core/themes/stark/stark.info.yml', 'stark.theme'),
      ]);

    $theme_data = $this->themeHandler->rebuildThemeData();
    $this->assertCount(1, $theme_data);
    $info = $theme_data['stark'];

    // Ensure some basic properties.
    $this->assertInstanceOf('Drupal\Core\Extension\Extension', $info);
    $this->assertEquals('stark', $info->getName());
    $this->assertEquals('core/themes/stark/stark.info.yml', $info->getPathname());
    $this->assertEquals('core/themes/stark/stark.theme', $info->getExtensionPathname());

  }

  /**
   * Tests empty libraries in theme.info.yml file.
   */
  public function testThemeLibrariesEmpty() {
    $theme = new Extension($this->root, 'theme', 'core/modules/system/tests/themes/test_theme_libraries_empty', 'test_theme_libraries_empty.info.yml');
    try {
      $this->themeHandler->addTheme($theme);
      $this->assertTrue(TRUE, 'Empty libraries key in theme.info.yml does not cause PHP warning');
    }
    catch (\Exception $e) {
      $this->fail('Empty libraries key in theme.info.yml causes PHP warning.');
    }
  }

}

/**
 * Extends the default theme handler to mock some drupal_ methods.
 */
class StubThemeHandler extends ThemeHandler {

  /**
   * Whether the CSS cache was cleared.
   *
   * @var bool
   */
  protected $clearedCssCache;

  /**
   * Whether the registry should be rebuilt.
   *
   * @var bool
   */
  protected $registryRebuild;

  /**
   * {@inheritdoc}
   */
  protected function clearCssCache() {
    $this->clearedCssCache = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function themeRegistryRebuild() {
    $this->registryRebuild = TRUE;
  }

}
