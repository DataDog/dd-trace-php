<?php

namespace Drupal\Tests\locale\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\locale\StringStorageInterface;
use Drupal\Core\Language\LanguageInterface;

/**
 * Tests translation of configuration strings.
 *
 * @group locale
 */
class LocaleConfigTranslationTest extends BrowserTestBase {

  /**
   * The language code used.
   *
   * @var string
   */
  protected $langcode;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['locale', 'contact', 'contact_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * @var \Drupal\locale\StringStorageInterface
   */
  protected StringStorageInterface $storage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Add a default locale storage for all these tests.
    $this->storage = $this->container->get('locale.storage');

    // Enable import of translations. By default this is disabled for automated
    // tests.
    $this->config('locale.settings')
      ->set('translation.import_enabled', TRUE)
      ->set('translation.use_source', LOCALE_TRANSLATION_USE_SOURCE_LOCAL)
      ->save();

    // Add custom language.
    $this->langcode = 'xx';
    $admin_user = $this->drupalCreateUser([
      'administer languages',
      'access administration pages',
      'translate interface',
      'administer modules',
      'access site-wide contact form',
      'administer contact forms',
      'administer site configuration',
    ]);
    $this->drupalLogin($admin_user);
    $name = $this->randomMachineName(16);
    $edit = [
      'predefined_langcode' => 'custom',
      'langcode' => $this->langcode,
      'label' => $name,
      'direction' => LanguageInterface::DIRECTION_LTR,
    ];
    $this->drupalGet('admin/config/regional/language/add');
    $this->submitForm($edit, 'Add custom language');
    // Set path prefix.
    $edit = ["prefix[$this->langcode]" => $this->langcode];
    $this->drupalGet('admin/config/regional/language/detection/url');
    $this->submitForm($edit, 'Save configuration');
  }

  /**
   * Tests basic configuration translation.
   */
  public function testConfigTranslation() {
    // Check that the maintenance message exists and create translation for it.
    $source = '@site is currently under maintenance. We should be back shortly. Thank you for your patience.';
    $string = $this->storage->findString(['source' => $source, 'context' => '', 'type' => 'configuration']);
    $this->assertNotEmpty($string, 'Configuration strings have been created upon installation.');

    // Translate using the UI so configuration is refreshed.
    $message = $this->randomMachineName(20);
    $search = [
      'string' => $string->source,
      'langcode' => $this->langcode,
      'translation' => 'all',
    ];
    $this->drupalGet('admin/config/regional/translate');
    $this->submitForm($search, 'Filter');
    $textarea = $this->assertSession()->elementExists('xpath', '//textarea');
    $lid = $textarea->getAttribute('name');
    $edit = [
      $lid => $message,
    ];
    $this->drupalGet('admin/config/regional/translate');
    $this->submitForm($edit, 'Save translations');

    // Get translation and check we've only got the message.
    $translation = \Drupal::languageManager()->getLanguageConfigOverride($this->langcode, 'system.maintenance')->get();
    $this->assertCount(1, $translation, 'Got the right number of properties after translation.');
    $this->assertEquals($message, $translation['message']);

    // Check default medium date format exists and create a translation for it.
    $string = $this->storage->findString(['source' => 'D, m/d/Y - H:i', 'context' => 'PHP date format', 'type' => 'configuration']);
    $this->assertNotEmpty($string, 'Configuration date formats have been created upon installation.');

    // Translate using the UI so configuration is refreshed.
    $search = [
      'string' => $string->source,
      'langcode' => $this->langcode,
      'translation' => 'all',
    ];
    $this->drupalGet('admin/config/regional/translate');
    $this->submitForm($search, 'Filter');
    $textarea = $this->assertSession()->elementExists('xpath', '//textarea');
    $lid = $textarea->getAttribute('name');
    $edit = [
      $lid => 'D',
    ];
    $this->drupalGet('admin/config/regional/translate');
    $this->submitForm($edit, 'Save translations');

    $translation = \Drupal::languageManager()->getLanguageConfigOverride($this->langcode, 'core.date_format.medium')->get();
    $this->assertEquals('D', $translation['pattern'], 'Got the right date format pattern after translation.');

    // Formatting the date 8 / 27 / 1985 @ 13:37 EST with pattern D should
    // display "Tue".
    $formatted_date = $this->container->get('date.formatter')->format(494015820, $type = 'medium', NULL, 'America/New_York', $this->langcode);
    $this->assertEquals('Tue', $formatted_date, 'Got the right formatted date using the date format translation pattern.');

    // Assert strings from image module config are not available.
    $string = $this->storage->findString(['source' => 'Medium (220×220)', 'context' => '', 'type' => 'configuration']);
    $this->assertNull($string, 'Configuration strings have been created upon installation.');

    // Enable the image module.
    $this->drupalGet('admin/modules');
    $this->submitForm(['modules[image][enable]' => "1"], 'Install');
    $this->rebuildContainer();

    $string = $this->storage->findString(['source' => 'Medium (220×220)', 'context' => '', 'type' => 'configuration']);
    $this->assertNotEmpty($string, 'Configuration strings have been created upon installation.');
    $locations = $string->getLocations();
    // Check the configuration string has been created with the right location.
    $this->assertArrayHasKey('configuration', $locations);
    $this->assertArrayHasKey('image.style.medium', $locations['configuration']);

    // Check the string is unique and has no translation yet.
    $translations = $this->storage->getTranslations(['language' => $this->langcode, 'type' => 'configuration', 'name' => 'image.style.medium']);
    $this->assertCount(1, $translations);
    $translation = reset($translations);
    $this->assertEquals($string->source, $translation->source);
    $this->assertEmpty($translation->translation);

    // Translate using the UI so configuration is refreshed.
    $image_style_label = $this->randomMachineName(20);
    $search = [
      'string' => $string->source,
      'langcode' => $this->langcode,
      'translation' => 'all',
    ];
    $this->drupalGet('admin/config/regional/translate');
    $this->submitForm($search, 'Filter');
    $textarea = $this->assertSession()->elementExists('xpath', '//textarea');
    $lid = $textarea->getAttribute('name');
    $edit = [
      $lid => $image_style_label,
    ];
    $this->drupalGet('admin/config/regional/translate');
    $this->submitForm($edit, 'Save translations');

    // Check the right single translation has been created.
    $translations = $this->storage->getTranslations(['language' => $this->langcode, 'type' => 'configuration', 'name' => 'image.style.medium']);
    $translation = reset($translations);
    $this->assertCount(1, $translations, 'Got only one translation for image configuration.');
    $this->assertEquals($string->source, $translation->source);
    $this->assertEquals($image_style_label, $translation->translation);

    // Try more complex configuration data.
    $translation = \Drupal::languageManager()->getLanguageConfigOverride($this->langcode, 'image.style.medium')->get();
    $this->assertEquals($image_style_label, $translation['label'], 'Got the right translation for image style name after translation');

    // Uninstall the module.
    $this->drupalGet('admin/modules/uninstall');
    $this->submitForm(['uninstall[image]' => "image"], 'Uninstall');
    $this->submitForm([], 'Uninstall');

    // Ensure that the translated configuration has been removed.
    $override = \Drupal::languageManager()->getLanguageConfigOverride('xx', 'image.style.medium');
    $this->assertTrue($override->isNew(), 'Translated configuration for image module removed.');

    // Translate default category using the UI so configuration is refreshed.
    $category_label = $this->randomMachineName(20);
    $search = [
      'string' => 'Website feedback',
      'langcode' => $this->langcode,
      'translation' => 'all',
    ];
    $this->drupalGet('admin/config/regional/translate');
    $this->submitForm($search, 'Filter');
    $textarea = $this->assertSession()->elementExists('xpath', '//textarea');
    $lid = $textarea->getAttribute('name');
    $edit = [
      $lid => $category_label,
    ];
    $this->drupalGet('admin/config/regional/translate');
    $this->submitForm($edit, 'Save translations');

    // Check if this category displayed in this language will use the
    // translation. This test ensures the entity loaded from the request
    // upcasting will already work.
    $this->drupalGet($this->langcode . '/contact/feedback');
    $this->assertSession()->pageTextContains($category_label);

    // Check if the UI does not show the translated String.
    $this->drupalGet('admin/structure/contact/manage/feedback');
    $this->assertSession()->fieldValueEquals('edit-label', 'Website feedback');
  }

  /**
   * Tests translatability of optional configuration in locale.
   */
  public function testOptionalConfiguration() {
    $this->assertNodeConfig(FALSE, FALSE);
    // Enable the node module.
    $this->drupalGet('admin/modules');
    $this->submitForm(['modules[node][enable]' => "1"], 'Install');
    $this->submitForm([], 'Continue');
    $this->rebuildContainer();
    $this->assertNodeConfig(TRUE, FALSE);
    // Enable the views module (which node provides some optional config for).
    $this->drupalGet('admin/modules');
    $this->submitForm(['modules[views][enable]' => "1"], 'Install');
    $this->rebuildContainer();
    $this->assertNodeConfig(TRUE, TRUE);
  }

  /**
   * Check that node configuration source strings are made available in locale.
   *
   * @param bool $required
   *   Whether to assume a sample of the required default configuration is
   *   present.
   * @param bool $optional
   *   Whether to assume a sample of the optional default configuration is
   *   present.
   *
   * @internal
   */
  protected function assertNodeConfig(bool $required, bool $optional): void {
    // Check the required default configuration in node module.
    $string = $this->storage->findString(['source' => 'Make content sticky', 'context' => '', 'type' => 'configuration']);
    if ($required) {
      $this->assertFalse($this->config('system.action.node_make_sticky_action')->isNew());
      $this->assertNotEmpty($string, 'Node action text can be found with node module.');
    }
    else {
      $this->assertTrue($this->config('system.action.node_make_sticky_action')->isNew());
      $this->assertNull($string, 'Node action text can not be found without node module.');
    }

    // Check the optional default configuration in node module.
    $string = $this->storage->findString(['source' => 'No front page content has been created yet.<br/>Follow the <a target="_blank" href="https://www.drupal.org/docs/user_guide/en/index.html">User Guide</a> to start building your site.', 'context' => '', 'type' => 'configuration']);
    if ($optional) {
      $this->assertFalse($this->config('views.view.frontpage')->isNew());
      $this->assertNotEmpty($string, 'Node view text can be found with node and views modules.');
    }
    else {
      $this->assertTrue($this->config('views.view.frontpage')->isNew());
      $this->assertNull($string, 'Node view text can not be found without node and/or views modules.');
    }
  }

}
