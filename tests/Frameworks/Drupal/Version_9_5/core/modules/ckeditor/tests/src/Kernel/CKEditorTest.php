<?php

namespace Drupal\Tests\ckeditor\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\editor\Entity\Editor;
use Drupal\filter\Entity\FilterFormat;

/**
 * Tests for the 'CKEditor' text editor plugin.
 *
 * @group ckeditor
 * @group legacy
 */
class CKEditorTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'system',
    'user',
    'filter',
    'editor',
    'ckeditor',
    'filter_test',
  ];

  /**
   * An instance of the "CKEditor" text editor plugin.
   *
   * @var \Drupal\ckeditor\Plugin\Editor\CKEditor
   */
  protected $ckeditor;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The Editor Plugin Manager.
   *
   * @var \Drupal\editor\Plugin\EditorManager
   */
  protected $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->fileUrlGenerator = $this->container->get('file_url_generator');

    // Install the Filter module.

    // Create text format, associate CKEditor.
    $filtered_html_format = FilterFormat::create([
      'format' => 'filtered_html',
      'name' => 'Filtered HTML',
      'weight' => 0,
      'filters' => [
        'filter_html' => [
          'status' => 1,
          'settings' => [
            'allowed_html' => '<h2 id> <h3> <h4> <h5> <h6> <p> <br> <strong> <a href hreflang>',
          ],
        ],
      ],
    ]);
    $filtered_html_format->save();
    $editor = Editor::create([
      'format' => 'filtered_html',
      'editor' => 'ckeditor',
    ]);
    $editor->save();

    // Create "CKEditor" text editor plugin instance.
    $this->ckeditor = $this->container->get('plugin.manager.editor')->createInstance('ckeditor');
  }

  /**
   * Tests CKEditor::getJSSettings().
   */
  public function testGetJSSettings() {
    $editor = Editor::load('filtered_html');
    $query_string = '?0=';

    // Default toolbar.
    $expected_config = $this->getDefaultInternalConfig() + [
      'drupalImage_dialogTitleAdd' => 'Insert Image',
      'drupalImage_dialogTitleEdit' => 'Edit Image',
      'drupalLink_dialogTitleAdd' => 'Add Link',
      'drupalLink_dialogTitleEdit' => 'Edit Link',
      'allowedContent' => $this->getDefaultAllowedContentConfig(),
      'disallowedContent' => $this->getDefaultDisallowedContentConfig(),
      'toolbar' => $this->getDefaultToolbarConfig(),
      'contentsCss' => $this->getDefaultContentsCssConfig(),
      'extraPlugins' => 'drupalimage,drupallink',
      'language' => 'en',
      'stylesSet' => FALSE,
      'drupalExternalPlugins' => [
        'drupalimage' => $this->fileUrlGenerator->generateString('core/modules/ckeditor/js/plugins/drupalimage/plugin.js'),
        'drupallink' => $this->fileUrlGenerator->generateString('core/modules/ckeditor/js/plugins/drupallink/plugin.js'),
      ],
    ];
    $this->assertEquals($expected_config, $this->ckeditor->getJSSettings($editor), 'Generated JS settings are correct for default configuration.');

    // Customize the configuration: add button, have two contextually enabled
    // buttons, and configure a CKEditor plugin setting.
    $this->enableModules(['ckeditor_test']);
    $this->container->get('plugin.manager.editor')->clearCachedDefinitions();
    $this->ckeditor = $this->container->get('plugin.manager.editor')->createInstance('ckeditor');
    $this->container->get('plugin.manager.ckeditor.plugin')->clearCachedDefinitions();
    $settings = $editor->getSettings();
    $settings['toolbar']['rows'][0][0]['items'][] = 'Strike';
    $settings['toolbar']['rows'][0][0]['items'][] = 'Format';
    $editor->setSettings($settings);
    $editor->save();
    $expected_config['toolbar'][0]['items'][] = 'Strike';
    $expected_config['toolbar'][0]['items'][] = 'Format';
    $expected_config['format_tags'] = 'p;h2;h3;h4;h5;h6';
    $expected_config['extraPlugins'] .= ',llama_contextual,llama_contextual_and_button';
    $expected_config['drupalExternalPlugins']['llama_contextual'] = $this->fileUrlGenerator->generateString('core/modules/ckeditor/tests/modules/js/llama_contextual.js');
    $expected_config['drupalExternalPlugins']['llama_contextual_and_button'] = $this->fileUrlGenerator->generateString('core/modules/ckeditor/tests/modules/js/llama_contextual_and_button.js');
    $expected_config['contentsCss'][] = $this->fileUrlGenerator->generateString('core/modules/ckeditor/tests/modules/ckeditor_test.css') . $query_string;
    $this->assertEquals($expected_config, $this->ckeditor->getJSSettings($editor), 'Generated JS settings are correct for customized configuration.');

    // Change the allowed HTML tags; the "allowedContent" and "format_tags"
    // settings for CKEditor should automatically be updated as well.
    $format = $editor->getFilterFormat();
    $format->filters('filter_html')->settings['allowed_html'] .= '<pre class> <h1> <blockquote class="*"> <address class="foo bar-* *">';
    $format->save();

    $expected_config['allowedContent']['pre'] = ['attributes' => 'class', 'styles' => FALSE, 'classes' => TRUE];
    $expected_config['allowedContent']['h1'] = ['attributes' => FALSE, 'styles' => FALSE, 'classes' => FALSE];
    $expected_config['allowedContent']['blockquote'] = ['attributes' => 'class', 'styles' => FALSE, 'classes' => TRUE];
    $expected_config['allowedContent']['address'] = ['attributes' => 'class', 'styles' => FALSE, 'classes' => 'foo,bar-*'];
    $expected_config['format_tags'] = 'p;h1;h2;h3;h4;h5;h6;pre';
    $this->assertEquals($expected_config, $this->ckeditor->getJSSettings($editor), 'Generated JS settings are correct for customized configuration.');

    // Disable the filter_html filter: allow *all *tags.
    $format->setFilterConfig('filter_html', ['status' => 0]);
    $format->save();

    $expected_config['allowedContent'] = TRUE;
    $expected_config['disallowedContent'] = FALSE;
    $expected_config['format_tags'] = 'p;h1;h2;h3;h4;h5;h6;pre';
    $this->assertEquals($expected_config, $this->ckeditor->getJSSettings($editor), 'Generated JS settings are correct for customized configuration.');

    // Enable the filter_test_restrict_tags_and_attributes filter.
    $format->setFilterConfig('filter_test_restrict_tags_and_attributes', [
      'status' => 1,
      'settings' => [
        'restrictions' => [
          'allowed' => [
            'p' => TRUE,
            'a' => [
              'href' => TRUE,
              'rel' => ['nofollow' => TRUE],
              'class' => ['external' => TRUE],
              'target' => ['_blank' => FALSE],
            ],
            'span' => [
              'class' => ['dodo' => FALSE],
              'property' => ['dc:*' => TRUE],
              'rel' => ['foaf:*' => FALSE],
              'style' => ['underline' => FALSE, 'color' => FALSE, 'font-size' => TRUE],
            ],
            '*' => [
              'style' => FALSE,
              'on*' => FALSE,
              'class' => ['is-a-hipster-llama' => TRUE, 'and-more' => TRUE],
              'data-*' => TRUE,
            ],
            'del' => FALSE,
          ],
        ],
      ],
    ]);
    $format->save();

    $expected_config['allowedContent'] = [
      'p' => [
        'attributes' => TRUE,
        'styles' => FALSE,
        'classes' => 'is-a-hipster-llama,and-more',
      ],
      'a' => [
        'attributes' => 'href,rel,class,target',
        'styles' => FALSE,
        'classes' => 'external',
      ],
      'span' => [
        'attributes' => 'class,property,rel,style',
        'styles' => 'font-size',
        'classes' => FALSE,
      ],
      '*' => [
        'attributes' => 'class,data-*',
        'styles' => FALSE,
        'classes' => 'is-a-hipster-llama,and-more',
      ],
      'del' => [
        'attributes' => FALSE,
        'styles' => FALSE,
        'classes' => FALSE,
      ],
    ];
    $expected_config['disallowedContent'] = [
      'span' => [
        'styles' => 'underline,color',
        'classes' => 'dodo',
      ],
      '*' => [
        'attributes' => 'on*',
      ],
    ];
    $expected_config['format_tags'] = 'p';
    $this->assertEquals($expected_config, $this->ckeditor->getJSSettings($editor), 'Generated JS settings are correct for customized configuration.');
  }

  /**
   * Tests CKEditor::buildToolbarJSSetting().
   */
  public function testBuildToolbarJSSetting() {
    $editor = Editor::load('filtered_html');

    // Default toolbar.
    $expected = $this->getDefaultToolbarConfig();
    $this->assertSame($expected, $this->ckeditor->buildToolbarJSSetting($editor), '"toolbar" configuration part of JS settings built correctly for default toolbar.');

    // Customize the configuration.
    $settings = $editor->getSettings();
    $settings['toolbar']['rows'][0][0]['items'][] = 'Strike';
    $editor->setSettings($settings);
    $editor->save();
    $expected[0]['items'][] = 'Strike';
    $this->assertEquals($expected, $this->ckeditor->buildToolbarJSSetting($editor), '"toolbar" configuration part of JS settings built correctly for customized toolbar.');

    // Enable the editor_test module, customize further.
    $this->enableModules(['ckeditor_test']);
    $this->container->get('plugin.manager.ckeditor.plugin')->clearCachedDefinitions();
    // Override the label of a toolbar component.
    $settings['toolbar']['rows'][0][0]['name'] = 'JunkScience';
    $settings['toolbar']['rows'][0][0]['items'][] = 'Llama';
    $editor->setSettings($settings);
    $editor->save();
    $expected[0]['name'] = 'JunkScience';
    $expected[0]['items'][] = 'Llama';
    $this->assertEquals($expected, $this->ckeditor->buildToolbarJSSetting($editor), '"toolbar" configuration part of JS settings built correctly for customized toolbar with contrib module-provided CKEditor plugin.');
  }

  /**
   * Tests CKEditor::buildContentsCssJSSetting().
   */
  public function testBuildContentsCssJSSetting() {
    $editor = Editor::load('filtered_html');
    $query_string = '?0=';

    // Default toolbar.
    $expected = $this->getDefaultContentsCssConfig();
    $this->assertEquals($expected, $this->ckeditor->buildContentsCssJSSetting($editor), '"contentsCss" configuration part of JS settings built correctly for default toolbar.');

    // Enable the editor_test module, which implements hook_ckeditor_css_alter().
    $this->enableModules(['ckeditor_test']);
    $expected[] = $this->fileUrlGenerator->generateString($this->getModulePath('ckeditor_test') . '/ckeditor_test.css') . $query_string;
    $this->assertSame($expected, $this->ckeditor->buildContentsCssJSSetting($editor), '"contentsCss" configuration part of JS settings built correctly while a hook_ckeditor_css_alter() implementation exists.');

    // Enable LlamaCss plugin, which adds an additional CKEditor stylesheet.
    $this->container->get('plugin.manager.editor')->clearCachedDefinitions();
    $this->ckeditor = $this->container->get('plugin.manager.editor')->createInstance('ckeditor');
    $this->container->get('plugin.manager.ckeditor.plugin')->clearCachedDefinitions();
    $settings = $editor->getSettings();
    // LlamaCss: automatically enabled by adding its 'LlamaCSS' button.
    $settings['toolbar']['rows'][0][0]['items'][] = 'LlamaCSS';
    $editor->setSettings($settings);
    $editor->save();
    $expected[] = $this->fileUrlGenerator->generateString($this->getModulePath('ckeditor_test') . '/css/llama.css') . $query_string;
    $this->assertSame($expected, $this->ckeditor->buildContentsCssJSSetting($editor), '"contentsCss" configuration part of JS settings built correctly while a CKEditorPluginInterface implementation exists.');

    // Enable the Olivero theme, which specifies a CKEditor stylesheet.
    \Drupal::service('theme_installer')->install(['olivero']);
    $this->config('system.theme')->set('default', 'olivero')->save();
    $expected[] = $this->fileUrlGenerator->generateString('core/themes/olivero/css/base/fonts.css') . $query_string;
    $expected[] = $this->fileUrlGenerator->generateString('core/themes/olivero/css/base/base.css') . $query_string;
    $expected[] = $this->fileUrlGenerator->generateString('core/themes/olivero/css/components/embedded-media.css') . $query_string;
    $expected[] = $this->fileUrlGenerator->generateString('core/themes/olivero/css/components/table.css') . $query_string;
    $expected[] = $this->fileUrlGenerator->generateString('core/themes/olivero/css/components/text-content.css') . $query_string;
    $expected[] = $this->fileUrlGenerator->generateString('core/themes/olivero/css/theme/ckeditor-frame.css') . $query_string;
    $this->assertSame($expected, $this->ckeditor->buildContentsCssJSSetting($editor), '"contentsCss" configuration part of JS settings built correctly while a theme providing a CKEditor stylesheet exists.');
  }

  /**
   * Tests Internal::getConfig().
   */
  public function testInternalGetConfig() {
    $editor = Editor::load('filtered_html');
    $internal_plugin = $this->container->get('plugin.manager.ckeditor.plugin')->createInstance('internal');

    // Default toolbar.
    $expected = $this->getDefaultInternalConfig();
    $expected['disallowedContent'] = $this->getDefaultDisallowedContentConfig();
    $expected['allowedContent'] = $this->getDefaultAllowedContentConfig();
    $this->assertEquals($expected, $internal_plugin->getConfig($editor), '"Internal" plugin configuration built correctly for default toolbar.');

    // Format dropdown/button enabled: new setting should be present.
    $settings = $editor->getSettings();
    $settings['toolbar']['rows'][0][0]['items'][] = 'Format';
    $editor->setSettings($settings);
    $expected['format_tags'] = 'p;h2;h3;h4;h5;h6';
    $this->assertEquals($expected, $internal_plugin->getConfig($editor), '"Internal" plugin configuration built correctly for customized toolbar.');
  }

  /**
   * Tests StylesCombo::getConfig().
   */
  public function testStylesComboGetConfig() {
    $editor = Editor::load('filtered_html');
    $stylescombo_plugin = $this->container->get('plugin.manager.ckeditor.plugin')->createInstance('stylescombo');

    // Styles dropdown/button enabled: new setting should be present.
    $settings = $editor->getSettings();
    $settings['toolbar']['rows'][0][0]['items'][] = 'Styles';
    $settings['plugins']['stylescombo']['styles'] = '';
    $editor->setSettings($settings);
    $editor->save();
    $expected['stylesSet'] = [];
    $this->assertSame($expected, $stylescombo_plugin->getConfig($editor), '"StylesCombo" plugin configuration built correctly for customized toolbar.');

    // Configure the optional "styles" setting in odd ways that shouldn't affect
    // the end result.
    $settings['plugins']['stylescombo']['styles'] = "   \n";
    $editor->setSettings($settings);
    $editor->save();
    $this->assertSame($expected, $stylescombo_plugin->getConfig($editor));
    $settings['plugins']['stylescombo']['styles'] = "\r\n  \n  \r  \n ";
    $editor->setSettings($settings);
    $editor->save();
    $this->assertSame($expected, $stylescombo_plugin->getConfig($editor), '"StylesCombo" plugin configuration built correctly for customized toolbar.');

    // Now configure it properly, the end result should change.
    $settings['plugins']['stylescombo']['styles'] = "h1.title|Title\np.mAgical.Callout|Callout";
    $editor->setSettings($settings);
    $editor->save();
    $expected['stylesSet'] = [
      ['name' => 'Title', 'element' => 'h1', 'attributes' => ['class' => 'title']],
      ['name' => 'Callout', 'element' => 'p', 'attributes' => ['class' => 'mAgical Callout']],
    ];
    $this->assertSame($expected, $stylescombo_plugin->getConfig($editor), '"StylesCombo" plugin configuration built correctly for customized toolbar.');

    // Same configuration, but now interspersed with nonsense. Should yield the
    // same result.
    $settings['plugins']['stylescombo']['styles'] = "  h1 .title   |  Title \r \n\r  \np.mAgical  .Callout|Callout\r";
    $editor->setSettings($settings);
    $editor->save();
    $this->assertSame($expected, $stylescombo_plugin->getConfig($editor), '"StylesCombo" plugin configuration built correctly for customized toolbar.');

    // Slightly different configuration: class names are optional.
    $settings['plugins']['stylescombo']['styles'] = "      h1 |  Title ";
    $editor->setSettings($settings);
    $editor->save();
    $expected['stylesSet'] = [['name' => 'Title', 'element' => 'h1']];
    $this->assertSame($expected, $stylescombo_plugin->getConfig($editor), '"StylesCombo" plugin configuration built correctly for customized toolbar.');

    // Invalid syntax should cause stylesSet to be set to FALSE.
    $settings['plugins']['stylescombo']['styles'] = "h1";
    $editor->setSettings($settings);
    $editor->save();
    $expected['stylesSet'] = FALSE;
    $this->assertSame($expected, $stylescombo_plugin->getConfig($editor), '"StylesCombo" plugin configuration built correctly for customized toolbar.');

    // Configuration that includes a dash in either the element or class name.
    $settings['plugins']['stylescombo']['styles'] = "drupal-entity.has-dashes|Allowing Dashes";
    $editor->setSettings($settings);
    $editor->save();
    $expected['stylesSet'] = [
      [
        'name' => 'Allowing Dashes',
        'element' => 'drupal-entity',
        'attributes' => ['class' => 'has-dashes'],
      ],
    ];
    $this->assertSame($expected, $stylescombo_plugin->getConfig($editor), '"StylesCombo" plugin configuration built correctly for customized toolbar.');

  }

  /**
   * Tests language list availability in CKEditor.
   */
  public function testLanguages() {
    // Get CKEditor supported language codes and spot-check.
    $this->enableModules(['language']);
    $this->installConfig(['language']);
    $langcodes = $this->ckeditor->getLangcodes();

    // Language codes transformed with browser mappings.
    $this->assertSame('pt', $langcodes['pt-pt'], '"pt" properly resolved');
    $this->assertSame('zh-cn', $langcodes['zh-hans'], '"zh-hans" properly resolved');

    // Language code both in Drupal and CKEditor.
    $this->assertSame('gl', $langcodes['gl'], '"gl" properly resolved');

    // Language codes only in CKEditor.
    $this->assertSame('en-au', $langcodes['en-au'], '"en-au" properly resolved');
    $this->assertSame('sr-latn', $langcodes['sr-latn'], '"sr-latn" properly resolved');

    // No locale module, so even though languages are enabled, CKEditor should
    // still be in English.
    $this->assertCKEditorLanguage('en');
  }

  /**
   * Tests that CKEditor plugins participate in JS translation.
   */
  public function testJSTranslation() {
    $this->enableModules(['language', 'locale']);
    $this->installSchema('locale', 'locales_source');
    $this->installSchema('locale', 'locales_location');
    $this->installSchema('locale', 'locales_target');
    $editor = Editor::load('filtered_html');
    $this->ckeditor->getJSSettings($editor);
    $localeStorage = $this->container->get('locale.storage');
    $string = $localeStorage->findString(['source' => 'Edit Link', 'context' => '']);
    $this->assertNotEmpty($string, 'String from JavaScript file saved.');

    // With locale module, CKEditor should not adhere to the language selected.
    $this->assertCKEditorLanguage();
  }

  /**
   * Tests loading of theme's CKEditor stylesheets defined in the .info file.
   */
  public function testExternalStylesheets() {
    /** @var \Drupal\Core\Extension\ThemeInstallerInterface $theme_installer */
    $theme_installer = \Drupal::service('theme_installer');
    // Case 1: Install theme which has an absolute external CSS URL.
    $theme_installer->install(['test_ckeditor_stylesheets_external']);
    $this->config('system.theme')->set('default', 'test_ckeditor_stylesheets_external')->save();
    $expected = [
      'https://fonts.googleapis.com/css?family=Open+Sans',
    ];
    $this->assertSame($expected, _ckeditor_theme_css('test_ckeditor_stylesheets_external'));

    // Case 2: Install theme which has an external protocol-relative CSS URL.
    $theme_installer->install(['test_ckeditor_stylesheets_protocol_relative']);
    $this->config('system.theme')->set('default', 'test_ckeditor_stylesheets_protocol_relative')->save();
    $expected = [
      '//fonts.googleapis.com/css?family=Open+Sans',
    ];
    $this->assertSame($expected, _ckeditor_theme_css('test_ckeditor_stylesheets_protocol_relative'));

    // Case 3: Install theme which has a relative CSS URL.
    $theme_installer->install(['test_ckeditor_stylesheets_relative']);
    $this->config('system.theme')->set('default', 'test_ckeditor_stylesheets_relative')->save();
    $expected = [
      'core/modules/system/tests/themes/test_ckeditor_stylesheets_relative/css/yokotsoko.css',
    ];
    $this->assertSame($expected, _ckeditor_theme_css('test_ckeditor_stylesheets_relative'));

    // Case 4: Install theme which has a Drupal root CSS URL.
    $theme_installer->install(['test_ckeditor_stylesheets_drupal_root']);
    $this->config('system.theme')->set('default', 'test_ckeditor_stylesheets_drupal_root')->save();
    $expected = [
      'core/modules/system/tests/themes/test_ckeditor_stylesheets_drupal_root/css/yokotsoko.css',
    ];
    $this->assertSame($expected, _ckeditor_theme_css('test_ckeditor_stylesheets_drupal_root'));
  }

  /**
   * Assert that CKEditor picks the expected language when French is default.
   *
   * @param string $langcode
   *   Language code to assert for. Defaults to French. That is the default
   *   language set in this assertion.
   *
   * @internal
   */
  protected function assertCKEditorLanguage(string $langcode = 'fr'): void {
    // Set French as the site default language.
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->config('system.site')->set('default_langcode', 'fr')->save();

    // Reset the language manager so new negotiations attempts will fall back on
    // French. Reinject the language manager CKEditor to use the current one.
    $this->container->get('language_manager')->reset();
    $this->ckeditor = $this->container->get('plugin.manager.editor')->createInstance('ckeditor');

    // Test that we now get the expected language.
    $editor = Editor::load('filtered_html');
    $settings = $this->ckeditor->getJSSettings($editor);
    $this->assertEquals($langcode, $settings['language']);
  }

  protected function getDefaultInternalConfig() {
    return [
      'customConfig' => '',
      'pasteFromWordPromptCleanup' => TRUE,
      'resize_dir' => 'vertical',
      'justifyClasses' => ['text-align-left', 'text-align-center', 'text-align-right', 'text-align-justify'],
      'entities' => FALSE,
      'disableNativeSpellChecker' => FALSE,
    ];
  }

  protected function getDefaultAllowedContentConfig() {
    return [
      'h2' => ['attributes' => 'id', 'styles' => FALSE, 'classes' => FALSE],
      'h3' => ['attributes' => FALSE, 'styles' => FALSE, 'classes' => FALSE],
      'h4' => ['attributes' => FALSE, 'styles' => FALSE, 'classes' => FALSE],
      'h5' => ['attributes' => FALSE, 'styles' => FALSE, 'classes' => FALSE],
      'h6' => ['attributes' => FALSE, 'styles' => FALSE, 'classes' => FALSE],
      'p' => ['attributes' => FALSE, 'styles' => FALSE, 'classes' => FALSE],
      'br' => ['attributes' => FALSE, 'styles' => FALSE, 'classes' => FALSE],
      'strong' => ['attributes' => FALSE, 'styles' => FALSE, 'classes' => FALSE],
      'a' => ['attributes' => 'href,hreflang', 'styles' => FALSE, 'classes' => FALSE],
      '*' => ['attributes' => 'lang,dir', 'styles' => FALSE, 'classes' => FALSE],
    ];
  }

  protected function getDefaultDisallowedContentConfig() {
    return [
      '*' => ['attributes' => 'on*'],
    ];
  }

  protected function getDefaultToolbarConfig() {
    return [
      [
        'name' => 'Formatting',
        'items' => ['Bold', 'Italic'],
      ],
      [
        'name' => 'Links',
        'items' => ['DrupalLink', 'DrupalUnlink'],
      ],
      [
        'name' => 'Lists',
        'items' => ['BulletedList', 'NumberedList'],
      ],
      [
        'name' => 'Media',
        'items' => ['Blockquote', 'DrupalImage'],
      ],
      [
        'name' => 'Tools',
        'items' => ['Source'],
      ],
      '/',
    ];
  }

  protected function getDefaultContentsCssConfig() {
    $query_string = '?0=';
    return [
      $this->fileUrlGenerator->generateString('core/modules/ckeditor/css/ckeditor-iframe.css') . $query_string,
      $this->fileUrlGenerator->generateString('core/modules/system/css/components/align.module.css') . $query_string,
    ];
  }

}
