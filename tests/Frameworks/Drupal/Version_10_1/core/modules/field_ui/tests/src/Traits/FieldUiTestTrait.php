<?php

namespace Drupal\Tests\field_ui\Traits;

/**
 * Provides common functionality for the Field UI test classes.
 */
trait FieldUiTestTrait {

  /**
   * Creates a new field through the Field UI.
   *
   * @param string $bundle_path
   *   Admin path of the bundle that the new field is to be attached to.
   * @param string $field_name
   *   The field name of the new field storage.
   * @param string $label
   *   (optional) The label of the new field. Defaults to a random string.
   * @param string $field_type
   *   (optional) The field type of the new field storage. Defaults to
   *   'test_field'.
   * @param array $storage_edit
   *   (optional) $edit parameter for submitForm() on the second step
   *   ('Storage settings' form).
   * @param array $field_edit
   *   (optional) $edit parameter for submitForm() on the third step ('Field
   *   settings' form).
   */
  public function fieldUIAddNewField($bundle_path, $field_name, $label = NULL, $field_type = 'test_field', array $storage_edit = [], array $field_edit = []) {
    // Generate a label containing only letters and numbers to prevent random
    // test failure.
    // See https://www.drupal.org/project/drupal/issues/3030902
    $label = $label ?: $this->randomMachineName();
    $initial_edit = [
      'new_storage_type' => $field_type,
      'label' => $label,
      'field_name' => $field_name,
    ];

    // Allow the caller to set a NULL path in case they navigated to the right
    // page before calling this method.
    if ($bundle_path !== NULL) {
      $bundle_path = "$bundle_path/fields/add-field";
    }

    // First step: 'Add field' page.
    if ($bundle_path !== NULL) {
      $this->drupalGet($bundle_path);
    }
    $this->submitForm($initial_edit, 'Save and continue');
    $this->assertSession()->pageTextContains("These settings apply to the $label field everywhere it is used.");
    // Test Breadcrumbs.
    $this->assertSession()->linkExists($label, 0, 'Field label is correct in the breadcrumb of the storage settings page.');

    // Second step: 'Storage settings' form.
    $this->submitForm($storage_edit, 'Save field settings');
    $this->assertSession()->pageTextContains("Updated field $label field settings.");

    // Third step: 'Field settings' form.
    $this->submitForm($field_edit, 'Save settings');
    $this->assertSession()->pageTextContains("Saved $label configuration.");

    // Check that the field appears in the overview form.
    $xpath = $this->assertSession()->buildXPathQuery("//table[@id=\"field-overview\"]//tr/td[1 and text() = :label]", [
      ':label' => $label,
    ]);
    $this->assertSession()->elementExists('xpath', $xpath);
  }

  /**
   * Adds an existing field through the Field UI.
   *
   * @param string $bundle_path
   *   Admin path of the bundle that the field is to be attached to.
   * @param string $existing_storage_name
   *   The name of the existing field storage for which we want to add a new
   *   field.
   * @param string $label
   *   (optional) The label of the new field. Defaults to a random string.
   * @param array $field_edit
   *   (optional) $edit parameter for submitForm() on the second step
   *   ('Field settings' form).
   */
  public function fieldUIAddExistingField($bundle_path, $existing_storage_name, $label = NULL, array $field_edit = []) {
    $label = $label ?: $this->randomMachineName();
    $field_edit['edit-label'] = $label;

    // First step: navigate to the re-use field page.
    $this->drupalGet("{$bundle_path}/fields/");
    // Confirm that the local action is visible.
    $this->assertSession()->linkExists('Re-use an existing field');
    $this->clickLink('Re-use an existing field');
    $this->assertSession()->elementExists('css', "input[value=Re-use][name=$existing_storage_name]");
    $this->click("input[value=Re-use][name=$existing_storage_name]");

    // Set the main content to only the content region because the label can
    // contain HTML which will be auto-escaped by Twig.
    $this->assertSession()->responseContains('field-config-edit-form');
    // Check that the page does not have double escaped HTML tags.
    $this->assertSession()->responseNotContains('&amp;lt;');

    // Second step: 'Field settings' form.
    $this->submitForm($field_edit, 'Save settings');
    $this->assertSession()->pageTextContains("Saved $label configuration.");

    // Check that the field appears in the overview form.
    $xpath = $this->assertSession()->buildXPathQuery("//table[@id=\"field-overview\"]//tr/td[1 and text() = :label]", [
      ':label' => $label,
    ]);
    $this->assertSession()->elementExists('xpath', $xpath);
  }

  /**
   * Deletes a field through the Field UI.
   *
   * @param string $bundle_path
   *   Admin path of the bundle that the field is to be deleted from.
   * @param string $field_name
   *   The name of the field.
   * @param string $label
   *   The label of the field.
   * @param string $bundle_label
   *   The label of the bundle.
   */
  public function fieldUIDeleteField($bundle_path, $field_name, $label, $bundle_label) {
    // Display confirmation form.
    $this->drupalGet("$bundle_path/fields/$field_name/delete");
    $this->assertSession()->pageTextContains("Are you sure you want to delete the field $label");

    // Test Breadcrumbs.
    $this->assertSession()->linkExists($label, 0, 'Field label is correct in the breadcrumb of the field delete page.');

    // Submit confirmation form.
    $this->submitForm([], 'Delete');
    $this->assertSession()->pageTextContains("The field $label has been deleted from the $bundle_label content type.");

    // Check that the field does not appear in the overview form.
    $xpath = $this->assertSession()->buildXPathQuery('//table[@id="field-overview"]//span[@class="label-field" and text()= :label]', [
      ':label' => $label,
    ]);
    $this->assertSession()->elementNotExists('xpath', $xpath);
  }

}
