<?php

namespace Drupal\Tests\locale\Functional;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\Traits\Core\PathAliasTestTrait;

/**
 * Tests you can configure a language for individual URL aliases.
 *
 * @group locale
 * @group path
 */
class LocalePathTest extends BrowserTestBase {

  use PathAliasTestTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['node', 'locale', 'path', 'views'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);
    $this->config('system.site')->set('page.front', '/node')->save();
  }

  /**
   * Tests if a language can be associated with a path alias.
   */
  public function testPathLanguageConfiguration() {
    // User to add and remove language.
    $admin_user = $this->drupalCreateUser([
      'administer languages',
      'create page content',
      'administer url aliases',
      'create url aliases',
      'access administration pages',
      'access content overview',
    ]);

    // Add custom language.
    $this->drupalLogin($admin_user);
    // Code for the language.
    $langcode = 'xx';
    // The English name for the language.
    $name = $this->randomMachineName(16);
    // The domain prefix.
    $prefix = $langcode;
    $edit = [
      'predefined_langcode' => 'custom',
      'langcode' => $langcode,
      'label' => $name,
      'direction' => LanguageInterface::DIRECTION_LTR,
    ];
    $this->drupalGet('admin/config/regional/language/add');
    $this->submitForm($edit, 'Add custom language');

    // Set path prefix.
    $edit = ["prefix[$langcode]" => $prefix];
    $this->drupalGet('admin/config/regional/language/detection/url');
    $this->submitForm($edit, 'Save configuration');

    // Check that the "xx" front page is readily available because path prefix
    // negotiation is pre-configured.
    $this->drupalGet($prefix);
    $this->assertSession()->pageTextContains('Welcome!');

    // Create a node.
    $node = $this->drupalCreateNode(['type' => 'page']);

    // Create a path alias in default language (English).
    $path = 'admin/config/search/path/add';
    $english_path = $this->randomMachineName(8);
    $edit = [
      'path[0][value]' => '/node/' . $node->id(),
      'alias[0][value]' => '/' . $english_path,
      'langcode[0][value]' => 'en',
    ];
    $this->drupalGet($path);
    $this->submitForm($edit, 'Save');

    // Create a path alias in new custom language.
    $custom_language_path = $this->randomMachineName(8);
    $edit = [
      'path[0][value]' => '/node/' . $node->id(),
      'alias[0][value]' => '/' . $custom_language_path,
      'langcode[0][value]' => $langcode,
    ];
    $this->drupalGet($path);
    $this->submitForm($edit, 'Save');

    // Confirm English language path alias works.
    $this->drupalGet($english_path);
    $this->assertSession()->pageTextContains($node->label());

    // Confirm custom language path alias works.
    $this->drupalGet($prefix . '/' . $custom_language_path);
    $this->assertSession()->pageTextContains($node->label());

    // Create a custom path.
    $custom_path = $this->randomMachineName(8);

    // Check priority of language for alias by source path.
    $path_alias = $this->createPathAlias('/node/' . $node->id(), '/' . $custom_path, LanguageInterface::LANGCODE_NOT_SPECIFIED);
    $lookup_path = $this->container->get('path_alias.manager')->getAliasByPath('/node/' . $node->id(), 'en');
    $this->assertEquals('/' . $english_path, $lookup_path, 'English language alias has priority.');
    // Same check for language 'xx'.
    $lookup_path = $this->container->get('path_alias.manager')->getAliasByPath('/node/' . $node->id(), $prefix);
    $this->assertEquals('/' . $custom_language_path, $lookup_path, 'Custom language alias has priority.');
    $path_alias->delete();

    // Create language nodes to check priority of aliases.
    $first_node = $this->drupalCreateNode(['type' => 'page', 'promote' => 1, 'langcode' => 'en']);
    $second_node = $this->drupalCreateNode(['type' => 'page', 'promote' => 1, 'langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED]);

    // Assign a custom path alias to the first node with the English language.
    $this->createPathAlias('/node/' . $first_node->id(), '/' . $custom_path, $first_node->language()->getId());

    // Assign a custom path alias to second node with
    // LanguageInterface::LANGCODE_NOT_SPECIFIED.
    $this->createPathAlias('/node/' . $second_node->id(), '/' . $custom_path, $second_node->language()->getId());

    // Test that both node titles link to our path alias.
    $this->drupalGet('admin/content');
    $custom_path_url = Url::fromUserInput('/' . $custom_path)->toString();
    $this->assertSession()->elementExists('xpath', $this->assertSession()->buildXPathQuery('//a[@href=:href and normalize-space(text())=:title]', [
      ':href' => $custom_path_url,
      ':title' => $first_node->label(),
    ]));
    $this->assertSession()->elementExists('xpath', $this->assertSession()->buildXPathQuery('//a[@href=:href and normalize-space(text())=:title]', [
      ':href' => $custom_path_url,
      ':title' => $second_node->label(),
    ]));

    // Confirm that the custom path leads to the first node.
    $this->drupalGet($custom_path);
    $this->assertSession()->pageTextContains($first_node->label());

    // Confirm that the custom path with prefix leads to the second node.
    $this->drupalGet($prefix . '/' . $custom_path);
    $this->assertSession()->pageTextContains($second_node->label());

  }

}
