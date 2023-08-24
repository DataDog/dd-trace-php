<?php

namespace Drupal\Tests\config\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests that config overrides do not bleed through in entity forms and lists.
 *
 * @group config
 */
class ConfigEntityFormOverrideTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['config_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that overrides do not affect forms or listing screens.
   */
  public function testFormsWithOverrides() {
    $this->drupalLogin($this->drupalCreateUser([
      'administer site configuration',
    ]));

    $original_label = 'Default';
    $overridden_label = 'Overridden label';
    $edited_label = 'Edited label';

    $config_test_storage = $this->container->get('entity_type.manager')->getStorage('config_test');

    // Set up an override.
    $settings['config']['config_test.dynamic.dotted.default']['label'] = (object) [
      'value' => $overridden_label,
      'required' => TRUE,
    ];
    $this->writeSettings($settings);

    // Test that the overridden label is loaded with the entity.
    $this->assertEquals($overridden_label, $config_test_storage->load('dotted.default')->label());

    // Test that the original label on the listing page is intact.
    $this->drupalGet('admin/structure/config_test');
    $this->assertSession()->pageTextContains($original_label);
    $this->assertSession()->pageTextNotContains($overridden_label);

    // Test that the original label on the editing page is intact.
    $this->drupalGet('admin/structure/config_test/manage/dotted.default');
    $this->assertSession()->fieldValueEquals('label', $original_label);
    $this->assertSession()->pageTextNotContains($overridden_label);

    // Change to a new label and test that the listing now has the edited label.
    $edit = [
      'label' => $edited_label,
    ];
    $this->submitForm($edit, 'Save');
    $this->drupalGet('admin/structure/config_test');
    $this->assertSession()->pageTextNotContains($overridden_label);
    $this->assertSession()->pageTextContains($edited_label);

    // Test that the editing page now has the edited label.
    $this->drupalGet('admin/structure/config_test/manage/dotted.default');
    $this->assertSession()->fieldValueEquals('label', $edited_label);

    // Test that the overridden label is still loaded with the entity.
    $this->assertEquals($overridden_label, $config_test_storage->load('dotted.default')->label());
  }

}
