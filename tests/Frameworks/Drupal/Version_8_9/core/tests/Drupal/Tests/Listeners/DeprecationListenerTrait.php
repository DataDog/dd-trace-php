<?php

namespace Drupal\Tests\Listeners;

use Drupal\Tests\Traits\ExpectDeprecationTrait;
use PHPUnit\Framework\TestCase;
use PHPUnit\Util\Test;

/**
 * Removes deprecations that we are yet to fix.
 *
 * @internal
 *   This class will be removed once all the deprecation notices have been
 *   fixed.
 */
trait DeprecationListenerTrait {

  use ExpectDeprecationTrait;

  /**
   * The previous error handler.
   *
   * @var callable
   */
  private $previousHandler;

  protected function deprecationStartTest($test) {
    if ($test instanceof TestCase) {
      if ('disabled' !== getenv('SYMFONY_DEPRECATIONS_HELPER')) {
        $this->registerErrorHandler($test);
      }
      if ($this->willBeIsolated($test)) {
        putenv('DRUPAL_EXPECTED_DEPRECATIONS_SERIALIZE=' . tempnam(sys_get_temp_dir(), 'exdep'));
      }
    }
  }

  /**
   * Reacts to the end of a test.
   *
   * @param \PHPUnit\Framework\Test $test
   *   The test object that has ended its test run.
   * @param float $time
   *   The time the test took.
   */
  protected function deprecationEndTest($test, $time) {
    /** @var \PHPUnit\Framework\Test $test */
    if ($file = getenv('DRUPAL_EXPECTED_DEPRECATIONS_SERIALIZE')) {
      putenv('DRUPAL_EXPECTED_DEPRECATIONS_SERIALIZE');
      $expected_deprecations = file_get_contents($file);
      if ($expected_deprecations) {
        $test->expectedDeprecations(unserialize($expected_deprecations));
      }
    }
    if ($file = getenv('SYMFONY_DEPRECATIONS_SERIALIZE')) {
      $method = $test->getName(FALSE);
      if (strpos($method, 'testLegacy') === 0
        || strpos($method, 'provideLegacy') === 0
        || strpos($method, 'getLegacy') === 0
        || strpos(get_class($test), '\Legacy')
        || in_array('legacy', Test::getGroups(get_class($test), $method), TRUE)) {
        // This is a legacy test don't skip deprecations.
        return;
      }

      // Need to edit the file of deprecations to remove any skipped
      // deprecations.
      $deprecations = file_get_contents($file);
      $deprecations = $deprecations ? unserialize($deprecations) : [];
      $resave = FALSE;
      foreach ($deprecations as $key => $deprecation) {
        if (in_array($deprecation[1], static::getSkippedDeprecations())) {
          unset($deprecations[$key]);
          $resave = TRUE;
        }
      }
      if ($resave) {
        file_put_contents($file, serialize($deprecations));
      }
    }
  }

  /**
   * Determines if a test is isolated.
   *
   * @param \PHPUnit\Framework\TestCase $test
   *   The test to check.
   *
   * @return bool
   *   TRUE if the isolated, FALSE if not.
   */
  private function willBeIsolated($test) {
    if ($test->isInIsolation()) {
      return FALSE;
    }

    $r = new \ReflectionProperty($test, 'runTestInSeparateProcess');
    $r->setAccessible(TRUE);

    return $r->getValue($test);
  }

  /**
   * A list of deprecations to ignore whilst fixes are put in place.
   *
   * Do not add any new deprecations to this list. All deprecation errors will
   * eventually be removed from this list.
   *
   * @return string[]
   *   A list of deprecations to ignore.
   *
   * @internal
   *
   * @todo Fix all these deprecations and remove them from this list.
   *   https://www.drupal.org/project/drupal/issues/2959269
   *
   * @see https://www.drupal.org/node/2811561
   */
  public static function getSkippedDeprecations() {
    return [
      'MigrateCckField is deprecated in Drupal 8.3.x and will be removed before Drupal 9.0.x. Use \Drupal\migrate_drupal\Annotation\MigrateField instead.',
      'MigrateCckFieldPluginManager is deprecated in Drupal 8.3.x and will be removed before Drupal 9.0.x. Use \Drupal\migrate_drupal\Annotation\MigrateFieldPluginManager instead.',
      'MigrateCckFieldPluginManagerInterface is deprecated in Drupal 8.3.x and will be removed before Drupal 9.0.x. Use \Drupal\migrate_drupal\Annotation\MigrateFieldPluginManagerInterface instead.',
      'The "plugin.manager.migrate.cckfield" service is deprecated. You should use the \'plugin.manager.migrate.field\' service instead. See https://www.drupal.org/node/2751897',
      'The Symfony\Component\ClassLoader\ApcClassLoader class is deprecated since Symfony 3.3 and will be removed in 4.0. Use `composer install --apcu-autoloader` instead.',
      // The following deprecation is not triggered by DrupalCI testing since it
      // is a Windows only deprecation. Remove when core no longer uses
      // WinCacheClassLoader in \Drupal\Core\DrupalKernel::initializeSettings().
      'The Symfony\Component\ClassLoader\WinCacheClassLoader class is deprecated since Symfony 3.3 and will be removed in 4.0. Use `composer install --apcu-autoloader` instead.',
      // The following deprecation message is skipped for testing purposes.
      '\Drupal\Tests\SkippedDeprecationTest deprecation',
      // These deprecations are triggered by symfony/psr-http-message-factory
      // 1.2, which can be installed if you update dependencies on php 7 or
      // higher
      'The "Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory" class is deprecated since symfony/psr-http-message-bridge 1.2, use PsrHttpFactory instead.',
      'The "psr7.http_message_factory" service relies on the deprecated "Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory" class. It should either be deprecated or its implementation upgraded.',
      // This deprecation comes from behat/mink-browserkit-driver when updating
      // symfony/browser-kit to 4.3+.
      'The "Symfony\Component\BrowserKit\Response::getStatus()" method is deprecated since Symfony 4.3, use getStatusCode() instead.',
      'The "core/jquery.ui.checkboxradio" asset library is deprecated in drupal:8.8.0 and is removed from drupal:9.0.0. See https://www.drupal.org/node/3067969',
      'The "core/jquery.ui.controlgroup" asset library is deprecated in drupal:8.8.0 and is removed from drupal:9.0.0. See https://www.drupal.org/node/3067969',
      'The "core/html5shiv" asset library is deprecated in drupal:8.8.0 and is removed from drupal:9.0.0. See https://www.drupal.org/node/3086383',
      'The "core/matchmedia" asset library is deprecated in drupal:8.8.0 and is removed from drupal:9.0.0. See https://www.drupal.org/node/3086653',
      'The "core/matchmedia.addListener" asset library is deprecated in drupal:8.8.0 and is removed from drupal:9.0.0. See https://www.drupal.org/node/3086653',
      'The "core/classList" asset library is deprecated in drupal:8.8.0 and is removed from drupal:9.0.0. Use the native browser implementation instead. See https://www.drupal.org/node/3089511',
      'The "core/jquery.ui.datepicker" asset library is deprecated in drupal:8.8.0 and is removed from drupal:9.0.0. See https://www.drupal.org/node/3081864',
      'The "locale/drupal.locale.datepicker" asset library is deprecated in drupal:8.8.0 and is removed from drupal:9.0.0. See https://www.drupal.org/node/3081864',
      'The "https://www.drupal.org/link-relations/create" string as a RestResource plugin annotation URI path key is deprecated in Drupal 8.4.0, now a valid link relation type name must be specified, so "create" must be specified instead before Drupal 9.0.0. See https://www.drupal.org/node/2737401.',
    ];
  }

  /**
   * Registers an error handler that wraps Symfony's DeprecationErrorHandler.
   *
   * @see \Symfony\Bridge\PhpUnit\DeprecationErrorHandler
   * @see \Symfony\Bridge\PhpUnit\Legacy\SymfonyTestsListenerTrait
   */
  protected function registerErrorHandler($test) {
    $deprecation_handler = function ($type, $msg, $file, $line, $context = []) {
      // Skip listed deprecations.
      if ($type === E_USER_DEPRECATED && in_array($msg, self::getSkippedDeprecations(), TRUE)) {
        return;
      }
      return call_user_func($this->previousHandler, $type, $msg, $file, $line, $context);
    };

    if ($this->previousHandler) {
      set_error_handler($deprecation_handler);
      return;
    }
    $this->previousHandler = set_error_handler($deprecation_handler);

    // Register another listener so that we can remove the error handler before
    // Symfony's DeprecationErrorHandler checks that it is the currently
    // registered handler. Note this is done like this to ensure the error
    // handler is removed after SymfonyTestsListenerTrait::endTest() is called.
    // SymfonyTestsListenerTrait has its own error handler that needs to be
    // removed before this one.
    $test_result_object = $test->getTestResultObject();
    // It's possible that a test does not have a result object. This can happen
    // when a test class does not have any test methods.
    if ($test_result_object) {
      $reflection_class = new \ReflectionClass($test_result_object);
      $reflection_property = $reflection_class->getProperty('listeners');
      $reflection_property->setAccessible(TRUE);
      $listeners = $reflection_property->getValue($test_result_object);
      $listeners[] = new AfterSymfonyListener();
      $reflection_property->setValue($test_result_object, $listeners);
    }
  }

}
