<?php

/**
 * @file
 * Autoloader for Drupal PHPUnit testing.
 *
 * @see phpunit.xml.dist
 */

use Drupal\Component\Assertion\Handle;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Constraint\Count;
use PHPUnit\Framework\Error\Error;
use PHPUnit\Framework\Error\Warning;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\Exception;
use PHPUnit\Framework\MockObject\Matcher\InvokedRecorder;
use PHPUnit\Framework\SkippedTestError;
use PHPUnit\Framework\TestCase;
use PHPUnit\Util\Test;
use PHPUnit\Util\Xml;

/**
 * Finds all valid extension directories recursively within a given directory.
 *
 * @param string $scan_directory
 *   The directory that should be recursively scanned.
 *
 * @return array
 *   An associative array of extension directories found within the scanned
 *   directory, keyed by extension name.
 */
function drupal_phpunit_find_extension_directories($scan_directory) {
  $extensions = [];
  $dirs = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($scan_directory, \RecursiveDirectoryIterator::FOLLOW_SYMLINKS));
  foreach ($dirs as $dir) {
    if (strpos($dir->getPathname(), '.info.yml') !== FALSE) {
      // Cut off ".info.yml" from the filename for use as the extension name. We
      // use getRealPath() so that we can scan extensions represented by
      // directory aliases.
      $extensions[substr($dir->getFilename(), 0, -9)] = $dir->getPathInfo()
        ->getRealPath();
    }
  }
  return $extensions;
}

/**
 * Returns directories under which contributed extensions may exist.
 *
 * @param string $root
 *   (optional) Path to the root of the Drupal installation.
 *
 * @return array
 *   An array of directories under which contributed extensions may exist.
 */
function drupal_phpunit_contrib_extension_directory_roots($root = NULL) {
  if ($root === NULL) {
    $root = dirname(dirname(__DIR__));
  }
  $paths = [
    $root . '/core/modules',
    $root . '/core/profiles',
    $root . '/modules',
    $root . '/profiles',
    $root . '/themes',
  ];
  $sites_path = $root . '/sites';
  // Note this also checks sites/../modules and sites/../profiles.
  foreach (scandir($sites_path) as $site) {
    if ($site[0] === '.' || $site === 'simpletest') {
      continue;
    }
    $path = "$sites_path/$site";
    $paths[] = is_dir("$path/modules") ? realpath("$path/modules") : NULL;
    $paths[] = is_dir("$path/profiles") ? realpath("$path/profiles") : NULL;
    $paths[] = is_dir("$path/themes") ? realpath("$path/themes") : NULL;
  }
  return array_filter($paths, 'file_exists');
}

/**
 * Registers the namespace for each extension directory with the autoloader.
 *
 * @param array $dirs
 *   An associative array of extension directories, keyed by extension name.
 *
 * @return array
 *   An associative array of extension directories, keyed by their namespace.
 */
function drupal_phpunit_get_extension_namespaces($dirs) {
  $suite_names = ['Unit', 'Kernel', 'Functional', 'Build', 'FunctionalJavascript'];
  $namespaces = [];
  foreach ($dirs as $extension => $dir) {
    if (is_dir($dir . '/src')) {
      // Register the PSR-4 directory for module-provided classes.
      $namespaces['Drupal\\' . $extension . '\\'][] = $dir . '/src';
    }
    $test_dir = $dir . '/tests/src';
    if (is_dir($test_dir)) {
      foreach ($suite_names as $suite_name) {
        $suite_dir = $test_dir . '/' . $suite_name;
        if (is_dir($suite_dir)) {
          // Register the PSR-4 directory for PHPUnit-based suites.
          $namespaces['Drupal\\Tests\\' . $extension . '\\' . $suite_name . '\\'][] = $suite_dir;
        }
      }
      // Extensions can have a \Drupal\extension\Traits namespace for
      // cross-suite trait code.
      $trait_dir = $test_dir . '/Traits';
      if (is_dir($trait_dir)) {
        $namespaces['Drupal\\Tests\\' . $extension . '\\Traits\\'][] = $trait_dir;
      }
    }
  }
  return $namespaces;
}

// We define the COMPOSER_INSTALL constant, so that PHPUnit knows where to
// autoload from. This is needed for tests run in isolation mode, because
// phpunit.xml.dist is located in a non-default directory relative to the
// PHPUnit executable.
if (!defined('PHPUNIT_COMPOSER_INSTALL')) {
  define('PHPUNIT_COMPOSER_INSTALL', __DIR__ . '/../../autoload.php');
}

/**
 * Populate class loader with additional namespaces for tests.
 *
 * We run this in a function to avoid setting the class loader to a global
 * that can change. This change can cause unpredictable false positives for
 * phpunit's global state change watcher. The class loader can be retrieved from
 * composer at any time by requiring autoload.php.
 */
function drupal_phpunit_populate_class_loader() {

  /** @var \Composer\Autoload\ClassLoader $loader */
  $loader = require __DIR__ . '/../../autoload.php';

  // Start with classes in known locations.
  $loader->add('Drupal\\BuildTests', __DIR__);
  $loader->add('Drupal\\Tests', __DIR__);
  $loader->add('Drupal\\TestSite', __DIR__);
  $loader->add('Drupal\\KernelTests', __DIR__);
  $loader->add('Drupal\\FunctionalTests', __DIR__);
  $loader->add('Drupal\\FunctionalJavascriptTests', __DIR__);
  $loader->add('Drupal\\TestTools', __DIR__);

  if (!isset($GLOBALS['namespaces'])) {
    // Scan for arbitrary extension namespaces from core and contrib.
    $extension_roots = drupal_phpunit_contrib_extension_directory_roots();

    $dirs = array_map('drupal_phpunit_find_extension_directories', $extension_roots);
    $dirs = array_reduce($dirs, 'array_merge', []);
    $GLOBALS['namespaces'] = drupal_phpunit_get_extension_namespaces($dirs);
  }
  foreach ($GLOBALS['namespaces'] as $prefix => $paths) {
    $loader->addPsr4($prefix, $paths);
  }

  return $loader;
}

// Do class loader population.
drupal_phpunit_populate_class_loader();

// Set sane locale settings, to ensure consistent string, dates, times and
// numbers handling.
// @see \Drupal\Core\DrupalKernel::bootEnvironment()
setlocale(LC_ALL, 'C');

// Set appropriate configuration for multi-byte strings.
mb_internal_encoding('utf-8');
mb_language('uni');

// Set the default timezone. While this doesn't cause any tests to fail, PHP
// complains if 'date.timezone' is not set in php.ini. The Australia/Sydney
// timezone is chosen so all tests are run using an edge case scenario (UTC+10
// and DST). This choice is made to prevent timezone related regressions and
// reduce the fragility of the testing system in general.
date_default_timezone_set('Australia/Sydney');

// Runtime assertions. PHPUnit follows the php.ini assert.active setting for
// runtime assertions. By default this setting is on. Ensure exceptions are
// thrown if an assert fails, but this call does not turn runtime assertions on
// if they weren't on already.
Handle::register();

// PHPUnit 4 to PHPUnit 6 bridge. Tests written for PHPUnit 4 need to work on
// PHPUnit 6 with a minimum of fuss.
// @todo provided for BC; remove in Drupal 9.
class_alias(AssertionFailedError::class, '\PHPUnit_Framework_AssertionFailedError');
class_alias(Count::class, '\PHPUnit_Framework_Constraint_Count');
class_alias(Error::class, '\PHPUnit_Framework_Error');
class_alias(Warning::class, '\PHPUnit_Framework_Error_Warning');
class_alias(ExpectationFailedException::class, '\PHPUnit_Framework_ExpectationFailedException');
class_alias(Exception::class, '\PHPUnit_Framework_Exception');
class_alias(InvokedRecorder::class, '\PHPUnit_Framework_MockObject_Matcher_InvokedRecorder');
class_alias(SkippedTestError::class, '\PHPUnit_Framework_SkippedTestError');
class_alias(TestCase::class, '\PHPUnit_Framework_TestCase');
class_alias(Test::class, '\PHPUnit_Util_Test');
class_alias(Xml::class, '\PHPUnit_Util_XML');
