<?php

namespace Drupal\Tests\file\FunctionalJavascript;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\file\Entity\File;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\Tests\field_ui\Traits\FieldUiTestTrait;
use Drupal\Tests\file\Functional\FileFieldCreationTrait;
use Drupal\Tests\TestFileCreationTrait;

/**
 * Tests the file field widget, single and multi-valued, using AJAX upload.
 *
 * @group file
 */
class FileFieldWidgetTest extends WebDriverTestBase {

  use FieldUiTestTrait;
  use FileFieldCreationTrait;
  use TestFileCreationTrait;

  /**
   * An user with administration permissions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'file', 'file_module_test', 'field_ui'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->adminUser = $this->drupalCreateUser([
      'access content',
      'access administration pages',
      'administer site configuration',
      'administer users',
      'administer permissions',
      'administer content types',
      'administer node fields',
      'administer node display',
      'administer nodes',
      'bypass node access',
    ]);
    $this->drupalLogin($this->adminUser);
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
  }

  /**
   * Tests upload and remove buttons for multiple multi-valued File fields.
   */
  public function testMultiValuedWidget() {
    $type_name = 'article';
    $field_name = 'test_file_field_1';
    $field_name2 = 'test_file_field_2';
    $cardinality = 3;
    $this->createFileField($field_name, 'node', $type_name, ['cardinality' => $cardinality]);
    $this->createFileField($field_name2, 'node', $type_name, ['cardinality' => $cardinality]);

    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $test_file = current($this->getTestFiles('text'));
    $test_file_path = \Drupal::service('file_system')
      ->realpath($test_file->uri);

    $this->drupalGet("node/add/$type_name");
    foreach ([$field_name2, $field_name] as $each_field_name) {
      for ($delta = 0; $delta < 3; $delta++) {
        $page->attachFileToField('files[' . $each_field_name . '_' . $delta . '][]', $test_file_path);
        $this->assertNotNull($assert_session->waitForElementVisible('css', '[name="' . $each_field_name . '_' . $delta . '_remove_button"]'));
        $this->assertNull($assert_session->waitForButton($each_field_name . '_' . $delta . '_upload_button'));
      }
    }

    $num_expected_remove_buttons = 6;

    foreach ([$field_name, $field_name2] as $current_field_name) {
      // How many uploaded files for the current field are remaining.
      $remaining = 3;
      // Test clicking each "Remove" button. For extra robustness, test them out
      // of sequential order. They are 0-indexed, and get renumbered after each
      // iteration, so array(1, 1, 0) means:
      // - First remove the 2nd file.
      // - Then remove what is then the 2nd file (was originally the 3rd file).
      // - Then remove the first file.
      foreach ([1, 1, 0] as $delta) {
        // Ensure we have the expected number of Remove buttons, and that they
        // are numbered sequentially.
        $buttons = $this->xpath('//input[@type="submit" and @value="Remove"]');
        $this->assertCount($num_expected_remove_buttons, $buttons, new FormattableMarkup('There are %n "Remove" buttons displayed.', ['%n' => $num_expected_remove_buttons]));
        foreach ($buttons as $i => $button) {
          $key = $i >= $remaining ? $i - $remaining : $i;
          $check_field_name = $field_name2;
          if ($current_field_name == $field_name && $i < $remaining) {
            $check_field_name = $field_name;
          }

          $this->assertSame($check_field_name . '_' . $key . '_remove_button', $button->getAttribute('name'));
        }

        $button_name = $current_field_name . '_' . $delta . '_remove_button';
        $remove_button = $assert_session->waitForButton($button_name);
        $remove_button->click();

        $num_expected_remove_buttons--;
        $remaining--;

        // Ensure an "Upload" button for the current field is displayed with the
        // correct name.
        $upload_button_name = $current_field_name . '_' . $remaining . '_upload_button';
        $this->assertNotNull($assert_session->waitForButton($upload_button_name));
        $button = $this->assertSession()->buttonExists($upload_button_name);
        $this->assertSame('Upload', $button->getValue());

        // Verify that after removing a file, only one "Upload" button for each
        // possible field is displayed.
        $expected = $current_field_name == $field_name ? 1 : 2;
        $this->assertSession()->elementsCount('xpath', '//input[@type="submit" and @value="Upload"]', $expected);
      }
    }
  }

  /**
   * Tests uploading and remove buttons for a single-valued File field.
   */
  public function testSingleValuedWidget() {
    $type_name = 'article';
    $field_name = 'test_file_field_1';
    $cardinality = 1;
    $this->createFileField($field_name, 'node', $type_name, ['cardinality' => $cardinality]);

    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $test_file = current($this->getTestFiles('text'));
    $test_file_path = \Drupal::service('file_system')
      ->realpath($test_file->uri);

    $this->drupalGet("node/add/$type_name");

    $page->findField('title[0][value]')->setValue($this->randomString());

    $page->attachFileToField('files[' . $field_name . '_0]', $test_file_path);
    $remove_button = $assert_session->waitForElementVisible('css', '[name="' . $field_name . '_0_remove_button"]');
    $this->assertNotNull($remove_button);
    $remove_button->click();
    $upload_field = $assert_session->waitForElementVisible('css', 'input[type="file"]');
    $this->assertNotEmpty($upload_field);
    $page->attachFileToField('files[' . $field_name . '_0]', $test_file_path);
    $remove_button = $assert_session->waitForElementVisible('css', '[name="' . $field_name . '_0_remove_button"]');
    $this->assertNotNull($remove_button);
    $page->pressButton('Save');
    $page->hasContent($test_file->name);

    // Create a new node and try to upload a file with an invalid extension.
    $test_image = current($this->getTestFiles('image'));
    $test_image_path = \Drupal::service('file_system')
      ->realpath($test_image->uri);

    $this->drupalGet("node/add/$type_name");

    $page->findField('title[0][value]')->setValue($this->randomString());
    $page->attachFileToField('files[' . $field_name . '_0]', $test_image_path);
    $messages = $assert_session->waitForElementVisible('css', '.file-upload-js-error');
    $this->assertEquals('The selected file image-test.png cannot be uploaded. Only files with the following extensions are allowed: txt.', $messages->getText());
    // Make sure the error disappears when a valid file is uploaded.
    $page->attachFileToField('files[' . $field_name . '_0]', $test_file_path);
    $remove_button = $assert_session->waitForElementVisible('css', '[name="' . $field_name . '_0_remove_button"]');
    $this->assertNotEmpty($remove_button);
    $this->assertEmpty($this->cssSelect('.file-upload-js-error'));
  }

  /**
   * Tests uploading more files than allowed at once.
   */
  public function testUploadingMoreFilesThanAllowed() {
    $type_name = 'article';
    $field_name = 'test_file_field_1';
    $cardinality = 2;
    $this->createFileField($field_name, 'node', $type_name, ['cardinality' => $cardinality]);

    $web_driver = $this->getSession()->getDriver();
    $file_system = \Drupal::service('file_system');

    $files = array_slice($this->getTestFiles('text'), 0, 3);
    $real_paths = [];
    foreach ($files as $file) {
      $real_paths[] = $file_system->realpath($file->uri);
    }
    $remote_paths = [];
    foreach ($real_paths as $path) {
      $remote_paths[] = $web_driver->uploadFileAndGetRemoteFilePath($path);
    }

    // Tests that uploading multiple remote files works with remote path.
    $this->drupalGet("node/add/$type_name");
    $multiple_field = $this->getSession()->getPage()->findField('files[test_file_field_1_0][]');
    $multiple_field->setValue(implode("\n", $remote_paths));
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->pageTextContains("Field {$field_name} can only hold {$cardinality} values but there were 3 uploaded. The following files have been omitted as a result: text-2.txt.");
  }

  /**
   * Retrieves a sample file of the specified type.
   *
   * @return \Drupal\file\FileInterface
   *   The new unsaved file entity.
   */
  public function getTestFile($type_name, $size = NULL) {
    // Get a file to upload.
    $file = current($this->getTestFiles($type_name, $size));

    // Add a filesize property to files as would be read by
    // \Drupal\file\Entity\File::load().
    $file->filesize = filesize($file->uri);

    return File::create((array) $file);
  }

}
