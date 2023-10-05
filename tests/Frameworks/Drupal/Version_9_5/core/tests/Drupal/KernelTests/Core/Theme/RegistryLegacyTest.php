<?php

namespace Drupal\KernelTests\Core\Theme;

use Drupal\Core\Theme\Registry;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests legacy behavior of the ThemeRegistry class.
 *
 * @group Theme
 * @group legacy
 *
 * @todo Remove in https://www.drupal.org/project/drupal/issues/3097889
 */
class RegistryLegacyTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['theme_test', 'system'];

  protected $profile = 'testing';

  /**
   * Tests the theme registry with theme functions and multiple subthemes.
   */
  public function testMultipleSubThemes() {
    $this->expectDeprecation('Unsilenced deprecation: Theme functions are deprecated in drupal:8.0.0 and are removed from drupal:10.0.0. Use Twig templates instead of theme_theme_test(). See https://www.drupal.org/node/1831138');
    $theme_handler = \Drupal::service('theme_handler');
    \Drupal::service('module_installer')->install(['theme_legacy_test']);
    \Drupal::service('theme_installer')->install(['test_basetheme']);

    $registry_base_theme = new Registry($this->root, \Drupal::cache(), \Drupal::lock(), \Drupal::moduleHandler(), $theme_handler, \Drupal::service('theme.initialization'), \Drupal::service('cache.bootstrap'), \Drupal::service('extension.list.module'), 'test_basetheme');
    $registry_base_theme->setThemeManager(\Drupal::theme());

    $preprocess_functions = $registry_base_theme->get()['theme_test_function_suggestions']['preprocess functions'];
    $this->assertSame([
      'template_preprocess_theme_test_function_suggestions',
      'test_basetheme_preprocess_theme_test_function_suggestions',
    ], $preprocess_functions, "Theme functions don't have template_preprocess but do have template_preprocess_HOOK");
  }

  /**
   * Tests the theme registry with theme functions with suggestions.
   */
  public function testSuggestionPreprocessFunctions() {
    $this->expectDeprecation('Unsilenced deprecation: Theme functions are deprecated in drupal:8.0.0 and are removed from drupal:10.0.0. Use Twig templates instead of test_legacy_theme_theme_test_preprocess_suggestions__kitten__meerkat(). See https://www.drupal.org/node/1831138');
    $theme_handler = \Drupal::service('theme_handler');
    \Drupal::service('theme_installer')->install(['test_legacy_theme']);

    $registry_deprecated_theme = new Registry($this->root, \Drupal::cache(), \Drupal::lock(), \Drupal::moduleHandler(), $theme_handler, \Drupal::service('theme.initialization'), \Drupal::service('cache.bootstrap'), \Drupal::service('extension.list.module'), 'test_legacy_theme');
    $registry_deprecated_theme->setThemeManager(\Drupal::theme());

    $expected_preprocess_functions = [
      'template_preprocess',
      'theme_test_preprocess_theme_test_preprocess_suggestions',
      'test_theme_preprocess_theme_test_preprocess_suggestions',
      'test_theme_preprocess_theme_test_preprocess_suggestions__kitten',
    ];

    $preprocess_functions = $registry_deprecated_theme->get()['theme_test_preprocess_suggestions__kitten__meerkat']['preprocess functions'];
    $this->assertSame($expected_preprocess_functions, $preprocess_functions, 'Suggestion implemented as a function correctly inherits preprocess functions.');
  }

  /**
   * Tests the order of registry parameters.
   */
  public function testRegistryConstructorParameters() {
    $this->expectDeprecation('Calling Registry::__construct() without the $runtime_cache as an instance of CacheBackendInterface or the $module_list as an instance of ModuleExtensionList is deprecated in drupal:9.5.0 and is required in drupal:10.0.0. See https://www.drupal.org/node/3285131');
    new Registry($this->root, \Drupal::cache(), \Drupal::lock(), \Drupal::moduleHandler(), \Drupal::service('theme_handler'), \Drupal::service('theme.initialization'), 'test_legacy_theme', \Drupal::service('cache.bootstrap'), \Drupal::service('extension.list.module'));
  }

}
