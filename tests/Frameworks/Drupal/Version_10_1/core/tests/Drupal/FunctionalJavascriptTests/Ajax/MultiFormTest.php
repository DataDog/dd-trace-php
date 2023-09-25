<?php

namespace Drupal\FunctionalJavascriptTests\Ajax;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Tests AJAX-enabled forms when multiple instances of the form are on a page.
 *
 * @group Ajax
 */
class MultiFormTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'form_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Page']);

    // Create a multi-valued field for 'page' nodes to use for Ajax testing.
    $field_name = 'field_ajax_test';
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => $field_name,
      'type' => 'text',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'bundle' => 'page',
    ])->save();
    \Drupal::service('entity_display.repository')->getFormDisplay('node', 'page', 'default')
      ->setComponent($field_name, ['type' => 'text_textfield'])
      ->save();

    // Log in a user who can create 'page' nodes.
    $this->drupalLogin($this->drupalCreateUser(['create page content']));
  }

  /**
   * Tests that pages with the 'node_page_form' included twice work correctly.
   */
  public function testMultiForm() {
    // HTML IDs for elements within the field are potentially modified with
    // each Ajax submission, but these variables are stable and help target the
    // desired elements.
    $field_name = 'field_ajax_test';

    $form_xpath = '//form[starts-with(@id, "node-page-form")]';
    $field_xpath = '//div[contains(@class, "field--name-field-ajax-test")]';
    $button_name = $field_name . '_add_more';
    $button_xpath_suffix = '//input[@name="' . $button_name . '"]';
    $field_items_xpath_suffix = '//input[@type="text"]';

    // Ensure the initial page contains both node forms and the correct number
    // of field items and "add more" button for the multi-valued field within
    // each form.
    $this->drupalGet('form-test/two-instances-of-same-form');

    // Wait for javascript on the page to prepare the form attributes.
    $this->assertSession()->assertWaitOnAjaxRequest();

    $session = $this->getSession();
    $page = $session->getPage();
    $fields = $page->findAll('xpath', $form_xpath . $field_xpath);
    $this->assertCount(2, $fields);
    foreach ($fields as $field) {
      $this->assertCount(1, $field->findAll('xpath', '.' . $field_items_xpath_suffix), 'Found the correct number of field items on the initial page.');
      $this->assertNotNull($field->find('xpath', '.' . $button_xpath_suffix), 'Found the "add more" button on the initial page.');
    }

    $this->assertSession()->pageContainsNoDuplicateId();

    // Submit the "add more" button of each form twice. After each corresponding
    // page update, ensure the same as above.

    for ($i = 0; $i < 2; $i++) {
      $forms = $page->findAll('xpath', $form_xpath);
      foreach ($forms as $offset => $form) {
        $button = $form->findButton('Add another item');
        $this->assertNotNull($button, 'Add Another Item button exists');
        $button->press();

        // Wait for field to be added with ajax.
        $this->assertNotEmpty($page->waitFor(10, function () use ($form, $i) {
          return $form->findField('field_ajax_test[' . ($i + 1) . '][value]');
        }));

        // After AJAX request and response verify the correct number of text
        // fields (including title), as well as the "Add another item" button.
        $this->assertCount($i + 3, $form->findAll('css', 'input[type="text"]'), 'Found the correct number of field items after an AJAX submission.');
        $this->assertNotEmpty($form->findButton('Add another item'), 'Found the "add more" button after an AJAX submission.');
        $this->assertSession()->pageContainsNoDuplicateId();
      }
    }
  }

}
