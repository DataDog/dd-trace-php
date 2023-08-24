<?php

namespace Drupal\Tests\system\Functional\Form;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the SystemConfigFormTestBase class.
 *
 * @group Form
 */
class SystemConfigFormTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['form_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests the SystemConfigFormTestBase class.
   */
  public function testSystemConfigForm() {
    $this->drupalGet('form-test/system-config-form');
    $element = $this->xpath('//div[@id = :id]/input[contains(@class, :class)]', [':id' => 'edit-actions', ':class' => 'button--primary']);
    $this->assertNotEmpty($element, 'The primary action submit button was found.');
    $this->drupalPostForm(NULL, [], t('Save configuration'));
    $this->assertText(t('The configuration options have been saved.'));
  }

}
