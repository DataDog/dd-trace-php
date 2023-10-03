<?php

namespace Drupal\KernelTests\Core\File;

/**
 * Tests \Drupal\Core\File\FileSystem::scanDirectory.
 *
 * @coversDefaultClass \Drupal\Core\File\FileSystem
 * @group File
 */
class ScanDirectoryTest extends FileTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['file_test'];

  /**
   * @var string
   */
  protected $path;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    // Hardcode the location of the fixtures files as it is already known
    // and shouldn't change, and we don't yet have a way to retrieve their
    // location from drupal_get_filename() in a cached way.
    // @todo Remove as part of https://www.drupal.org/node/2186491
    $this->path = 'core/tests/fixtures/files';
    $this->fileSystem = $this->container->get('file_system');
  }

  /**
   * Check the format of the returned values.
   *
   * @covers ::scanDirectory
   */
  public function testReturn() {
    // Grab a listing of all the JavaScript files and check that they're
    // passed to the callback.
    $all_files = $this->fileSystem->scanDirectory($this->path, '/^javascript-/');
    ksort($all_files);
    $this->assertCount(2, $all_files, 'Found two, expected javascript files.');

    // Check the first file.
    $file = reset($all_files);
    $this->assertEqual(key($all_files), $file->uri, 'Correct array key was used for the first returned file.');
    $this->assertEqual($file->uri, $this->path . '/javascript-1.txt', 'First file name was set correctly.');
    $this->assertEqual($file->filename, 'javascript-1.txt', 'First basename was set correctly');
    $this->assertEqual($file->name, 'javascript-1', 'First name was set correctly.');

    // Check the second file.
    $file = next($all_files);
    $this->assertEqual(key($all_files), $file->uri, 'Correct array key was used for the second returned file.');
    $this->assertEqual($file->uri, $this->path . '/javascript-2.script', 'Second file name was set correctly.');
    $this->assertEqual($file->filename, 'javascript-2.script', 'Second basename was set correctly');
    $this->assertEqual($file->name, 'javascript-2', 'Second name was set correctly.');
  }

  /**
   * Check that the callback function is called correctly.
   *
   * @covers ::scanDirectory
   */
  public function testOptionCallback() {

    // When nothing is matched nothing should be passed to the callback.
    $all_files = $this->fileSystem->scanDirectory($this->path, '/^NONEXISTINGFILENAME/', ['callback' => 'file_test_file_scan_callback']);
    $this->assertCount(0, $all_files, 'No files were found.');
    $results = file_test_file_scan_callback();
    file_test_file_scan_callback_reset();
    $this->assertCount(0, $results, 'No files were passed to the callback.');

    // Grab a listing of all the JavaScript files and check that they're
    // passed to the callback.
    $all_files = $this->fileSystem->scanDirectory($this->path, '/^javascript-/', ['callback' => 'file_test_file_scan_callback']);
    $this->assertCount(2, $all_files, 'Found two, expected javascript files.');
    $results = file_test_file_scan_callback();
    file_test_file_scan_callback_reset();
    $this->assertCount(2, $results, 'Files were passed to the callback.');
  }

  /**
   * Check that the no-mask parameter is honored.
   *
   * @covers ::scanDirectory
   */
  public function testOptionNoMask() {
    // Grab a listing of all the JavaScript files.
    $all_files = $this->fileSystem->scanDirectory($this->path, '/^javascript-/');
    $this->assertCount(2, $all_files, 'Found two, expected javascript files.');

    // Now use the nomask parameter to filter out the .script file.
    $filtered_files = $this->fileSystem->scanDirectory($this->path, '/^javascript-/', ['nomask' => '/.script$/']);
    $this->assertCount(1, $filtered_files, 'Filtered correctly.');
  }

  /**
   * Check that key parameter sets the return value's key.
   *
   * @covers ::scanDirectory
   */
  public function testOptionKey() {
    // "filename", for the path starting with $dir.
    $expected = [$this->path . '/javascript-1.txt', $this->path . '/javascript-2.script'];
    $actual = array_keys($this->fileSystem->scanDirectory($this->path, '/^javascript-/', ['key' => 'filepath']));
    sort($actual);
    $this->assertEqual($expected, $actual, 'Returned the correct values for the filename key.');

    // "basename", for the basename of the file.
    $expected = ['javascript-1.txt', 'javascript-2.script'];
    $actual = array_keys($this->fileSystem->scanDirectory($this->path, '/^javascript-/', ['key' => 'filename']));
    sort($actual);
    $this->assertEqual($expected, $actual, 'Returned the correct values for the basename key.');

    // "name" for the name of the file without an extension.
    $expected = ['javascript-1', 'javascript-2'];
    $actual = array_keys($this->fileSystem->scanDirectory($this->path, '/^javascript-/', ['key' => 'name']));
    sort($actual);
    $this->assertEqual($expected, $actual, 'Returned the correct values for the name key.');

    // Invalid option that should default back to "filename".
    $expected = [$this->path . '/javascript-1.txt', $this->path . '/javascript-2.script'];
    $actual = array_keys($this->fileSystem->scanDirectory($this->path, '/^javascript-/', ['key' => 'INVALID']));
    sort($actual);
    $this->assertEqual($expected, $actual, 'An invalid key defaulted back to the default.');
  }

  /**
   * Check that the recurse option descends into subdirectories.
   *
   * @covers ::scanDirectory
   */
  public function testOptionRecurse() {
    $files = $this->fileSystem->scanDirectory($this->path . '/..', '/^javascript-/', ['recurse' => FALSE]);
    $this->assertTrue(empty($files), "Without recursion couldn't find javascript files.");

    $files = $this->fileSystem->scanDirectory($this->path . '/..', '/^javascript-/', ['recurse' => TRUE]);
    $this->assertCount(2, $files, 'With recursion we found the expected javascript files.');
  }

  /**
   * Check that the min_depth options lets us ignore files in the starting
   * directory.
   *
   * @covers ::scanDirectory
   */
  public function testOptionMinDepth() {
    $files = $this->fileSystem->scanDirectory($this->path, '/^javascript-/', ['min_depth' => 0]);
    $this->assertCount(2, $files, 'No minimum-depth gets files in current directory.');

    $files = $this->fileSystem->scanDirectory($this->path, '/^javascript-/', ['min_depth' => 1]);
    $this->assertTrue(empty($files), 'Minimum-depth of 1 successfully excludes files from current directory.');
  }

  /**
   * Tests ::scanDirectory obeys 'file_scan_ignore_directories' setting.
   *
   * @covers ::scanDirectory
   */
  public function testIgnoreDirectories() {
    $files = $this->fileSystem->scanDirectory('core/modules/system/tests/fixtures/IgnoreDirectories', '/\.txt$/');
    $this->assertCount(2, $files, '2 text files found when not ignoring directories.');

    $this->setSetting('file_scan_ignore_directories', ['frontend_framework']);
    $files = $this->fileSystem->scanDirectory('core/modules/system/tests/fixtures/IgnoreDirectories', '/\.txt$/');
    $this->assertCount(1, $files, '1 text files found when ignoring directories called "frontend_framework".');

    // Ensure that the directories in file_scan_ignore_directories are escaped
    // using preg_quote.
    $this->setSetting('file_scan_ignore_directories', ['frontend.*']);
    $files = $this->fileSystem->scanDirectory('core/modules/system/tests/fixtures/IgnoreDirectories', '/\.txt$/');
    $this->assertCount(2, $files, '2 text files found when ignoring a directory that is not there.');

    $files = $this->fileSystem->scanDirectory('core/modules/system/tests/fixtures/IgnoreDirectories', '/\.txt$/', ['nomask' => '/^something_thing_else$/']);
    $this->assertCount(2, $files, '2 text files found when an "nomask" option is passed in.');
  }

}
