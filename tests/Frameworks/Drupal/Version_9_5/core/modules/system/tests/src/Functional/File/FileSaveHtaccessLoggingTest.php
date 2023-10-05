<?php

namespace Drupal\Tests\system\Functional\File;

use Drupal\Component\FileSecurity\FileSecurity;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests the log message added by file_save_htaccess().
 *
 * @group File
 */
class FileSaveHtaccessLoggingTest extends BrowserTestBase {

  protected static $modules = ['dblog'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests file_save_htaccess().
   */
  public function testHtaccessSave() {
    // Prepare test directories.
    $private = $this->publicFilesDirectory . '/test/private';

    // Verify that file_save_htaccess() returns FALSE if .htaccess cannot be
    // written and writes a correctly formatted message to the error log. Set
    // $private to TRUE so all possible .htaccess lines are written.
    /** @var \Drupal\Core\File\HtaccessWriterInterface $htaccess */
    $htaccess = \Drupal::service('file.htaccess_writer');
    $this->assertFalse($htaccess->write($private, TRUE));
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('admin/reports/dblog');
    $this->clickLink("Security warning: Couldn't write .htaccess file. Please…");

    $lines = FileSecurity::htaccessLines(TRUE);
    foreach (array_filter(explode("\n", $lines)) as $line) {
      $this->assertSession()->assertEscaped($line);
    }
  }

}
