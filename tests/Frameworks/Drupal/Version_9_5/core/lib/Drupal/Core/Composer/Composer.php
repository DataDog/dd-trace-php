<?php

namespace Drupal\Core\Composer;

use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Installer\PackageEvent;
use Composer\Script\Event;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\ProcessExecutor;
use Drupal\Component\FileSecurity\FileSecurity;

/**
 * Provides static functions for composer script events.
 *
 * @see https://getcomposer.org/doc/articles/scripts.md
 */
class Composer {

  protected static $packageToCleanup = [
    'behat/mink' => ['tests', 'driver-testsuite'],
    'behat/mink-selenium2-driver' => ['tests'],
    'composer/composer' => ['bin'],
    'drupal/coder' => ['coder_sniffer/Drupal/Test', 'coder_sniffer/DrupalPractice/Test'],
    'doctrine/instantiator' => ['tests'],
    'easyrdf/easyrdf' => ['scripts'],
    'egulias/email-validator' => ['documentation', 'tests'],
    'friends-of-behat/mink-browserkit-driver' => ['tests'],
    'guzzlehttp/promises' => ['tests'],
    'guzzlehttp/psr7' => ['tests'],
    'instaclick/php-webdriver' => ['doc', 'test'],
    'justinrainbow/json-schema' => ['demo'],
    'laminas/laminas-escaper' => ['doc'],
    'laminas/laminas-feed' => ['doc'],
    'laminas/laminas-stdlib' => ['doc'],
    'masterminds/html5' => ['bin', 'test'],
    'mikey179/vfsStream' => ['src/test'],
    'myclabs/deep-copy' => ['doc'],
    'pear/archive_tar' => ['docs', 'tests'],
    'pear/console_getopt' => ['tests'],
    'pear/pear-core-minimal' => ['tests'],
    'pear/pear_exception' => ['tests'],
    'phar-io/manifest' => ['examples', 'tests'],
    'phar-io/version' => ['tests'],
    'phpdocumentor/reflection-docblock' => ['tests'],
    'phpspec/prophecy' => ['fixtures', 'spec', 'tests'],
    'phpunit/php-code-coverage' => ['tests'],
    'phpunit/php-timer' => ['tests'],
    'phpunit/php-token-stream' => ['tests'],
    'phpunit/phpunit' => ['tests'],
    'sebastian/code-unit-reverse-lookup' => ['tests'],
    'sebastian/comparator' => ['tests'],
    'sebastian/diff' => ['tests'],
    'sebastian/environment' => ['tests'],
    'sebastian/exporter' => ['tests'],
    'sebastian/global-state' => ['tests'],
    'sebastian/object-enumerator' => ['tests'],
    'sebastian/object-reflector' => ['tests'],
    'sebastian/recursion-context' => ['tests'],
    'seld/jsonlint' => ['tests'],
    'squizlabs/php_codesniffer' => ['tests'],
    'stack/builder' => ['tests'],
    'symfony/browser-kit' => ['Tests'],
    'symfony/console' => ['Tests'],
    'symfony/css-selector' => ['Tests'],
    'symfony/debug' => ['Tests'],
    'symfony/dependency-injection' => ['Tests'],
    'symfony/dom-crawler' => ['Tests'],
    'symfony/filesystem' => ['Tests'],
    'symfony/finder' => ['Tests'],
    'symfony/error-handler' => ['Tests'],
    'symfony/event-dispatcher' => ['Tests'],
    'symfony/http-foundation' => ['Tests'],
    'symfony/http-kernel' => ['Tests'],
    'symfony/phpunit-bridge' => ['Tests'],
    'symfony/process' => ['Tests'],
    'symfony/psr-http-message-bridge' => ['Tests'],
    'symfony/routing' => ['Tests'],
    'symfony/serializer' => ['Tests'],
    'symfony/translation' => ['Tests'],
    'symfony/validator' => ['Tests', 'Resources'],
    'symfony/yaml' => ['Tests'],
    'symfony-cmf/routing' => ['Test', 'Tests'],
    'theseer/tokenizer' => ['tests'],
    'twig/twig' => ['doc', 'ext', 'test', 'tests'],
  ];

  /**
   * Add vendor classes to Composer's static classmap.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  public static function preAutoloadDump(Event $event) {
    // Get the configured vendor directory.
    $vendor_dir = $event->getComposer()->getConfig()->get('vendor-dir');

    // We need the root package so we can add our classmaps to its loader.
    $package = $event->getComposer()->getPackage();
    // We need the local repository so that we can query and see if it's likely
    // that our files are present there.
    $repository = $event->getComposer()->getRepositoryManager()->getLocalRepository();
    // This is, essentially, a null constraint. We only care whether the package
    // is present in the vendor directory yet, but findPackage() requires it.
    $constraint = new Constraint('>', '');
    // It's possible that there is no classmap specified in a custom project
    // composer.json file. We need one so we can optimize lookup for some of our
    // dependencies.
    $autoload = $package->getAutoload();
    if (!isset($autoload['classmap'])) {
      $autoload['classmap'] = [];
    }
    // Check for packages used prior to the default classloader being able to
    // use APCu and optimize them if they're present.
    // @see \Drupal\Core\DrupalKernel::boot()
    if ($repository->findPackage('symfony/http-foundation', $constraint)) {
      $autoload['classmap'] = array_merge($autoload['classmap'], [
        $vendor_dir . '/symfony/http-foundation/Request.php',
        $vendor_dir . '/symfony/http-foundation/RequestStack.php',
        $vendor_dir . '/symfony/http-foundation/ParameterBag.php',
        $vendor_dir . '/symfony/http-foundation/FileBag.php',
        $vendor_dir . '/symfony/http-foundation/ServerBag.php',
        $vendor_dir . '/symfony/http-foundation/HeaderBag.php',
        $vendor_dir . '/symfony/http-foundation/HeaderUtils.php',
      ]);
    }
    if ($repository->findPackage('symfony/http-kernel', $constraint)) {
      $autoload['classmap'] = array_merge($autoload['classmap'], [
        $vendor_dir . '/symfony/http-kernel/HttpKernel.php',
        $vendor_dir . '/symfony/http-kernel/HttpKernelInterface.php',
        $vendor_dir . '/symfony/http-kernel/TerminableInterface.php',
      ]);
    }
    if ($repository->findPackage('symfony/dependency-injection', $constraint)) {
      $autoload['classmap'] = array_merge($autoload['classmap'], [
        $vendor_dir . '/symfony/dependency-injection/ContainerAwareInterface.php',
        $vendor_dir . '/symfony/dependency-injection/ContainerInterface.php',
      ]);
    }
    if ($repository->findPackage('psr/container', $constraint)) {
      $autoload['classmap'] = array_merge($autoload['classmap'], [
        $vendor_dir . '/psr/container/src/ContainerInterface.php',
      ]);
    }
    if ($repository->findPackage('laminas/laminas-zendframework-bridge', $constraint)) {
      $autoload['classmap'] = array_merge($autoload['classmap'], [
        $vendor_dir . '/laminas/laminas-zendframework-bridge/src/Autoloader.php',
        $vendor_dir . '/laminas/laminas-zendframework-bridge/src/RewriteRules.php',
      ]);
    }
    $package->setAutoload($autoload);
  }

  /**
   * Ensures that .htaccess and web.config files are present in Composer root.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   *
   * @deprecated in drupal:9.5.0 and is removed from drupal:10.0.0. Any
   * "scripts" section mentioning this in composer.json can be removed and
   * replaced with the drupal/core-vendor-hardening Composer plugin, as needed.
   *
   * @see https://www.drupal.org/node/3260624
   */
  public static function ensureHtaccess(Event $event) {
    trigger_error('Calling ' . __METHOD__ . ' from composer.json is deprecated in drupal:9.5.0 and is removed from drupal:10.0.0. Any "scripts" section mentioning this in composer.json can be removed and replaced with the drupal/core-vendor-hardening Composer plugin, as needed. See https://www.drupal.org/node/3260624', E_USER_DEPRECATED);

    // The current working directory for composer scripts is where you run
    // composer from.
    $vendor_dir = $event->getComposer()->getConfig()->get('vendor-dir');

    // Prevent access to vendor directory on Apache servers.
    FileSecurity::writeHtaccess($vendor_dir);

    // Prevent access to vendor directory on IIS servers.
    FileSecurity::writeWebConfig($vendor_dir);
  }

  /**
   * Remove possibly problematic test files from vendored projects.
   *
   * @param \Composer\Installer\PackageEvent $event
   *   A PackageEvent object to get the configured composer vendor directories
   *   from.
   *
   * @deprecated in drupal:9.5.0 and is removed from drupal:10.0.0. Any
   * "scripts" section mentioning this in composer.json can be removed and
   * replaced with the drupal/core-vendor-hardening Composer plugin, as needed.
   *
   * @see https://www.drupal.org/node/3260624
   */
  public static function vendorTestCodeCleanup(PackageEvent $event) {
    trigger_error('Calling ' . __METHOD__ . ' from composer.json is deprecated in drupal:9.5.0 and is removed from drupal:10.0.0. Any "scripts" section mentioning this in composer.json can be removed and replaced with the drupal/core-vendor-hardening Composer plugin, as needed. See https://www.drupal.org/node/3260624', E_USER_DEPRECATED);

    $vendor_dir = $event->getComposer()->getConfig()->get('vendor-dir');
    $io = $event->getIO();
    $op = $event->getOperation();
    if ($op instanceof UpdateOperation) {
      $package = $op->getTargetPackage();
    }
    else {
      $package = $op->getPackage();
    }
    $package_key = static::findPackageKey($package->getName());
    $message = sprintf("    Processing <comment>%s</comment>", $package->getPrettyName());
    if ($io->isVeryVerbose()) {
      $io->write($message);
    }
    if ($package_key) {
      foreach (static::$packageToCleanup[$package_key] as $path) {
        $dir_to_remove = $vendor_dir . '/' . $package_key . '/' . $path;
        $print_message = $io->isVeryVerbose();
        if (is_dir($dir_to_remove)) {
          if (static::deleteRecursive($dir_to_remove)) {
            $message = sprintf("      <info>Removing directory '%s'</info>", $path);
          }
          else {
            // Always display a message if this fails as it means something has
            // gone wrong. Therefore the message has to include the package name
            // as the first informational message might not exist.
            $print_message = TRUE;
            $message = sprintf("      <error>Failure removing directory '%s'</error> in package <comment>%s</comment>.", $path, $package->getPrettyName());
          }
        }
        else {
          // If the package has changed or the --prefer-dist version does not
          // include the directory this is not an error.
          $message = sprintf("      Directory '%s' does not exist", $path);
        }
        if ($print_message) {
          $io->write($message);
        }
      }

      if ($io->isVeryVerbose()) {
        // Add a new line to separate this output from the next package.
        $io->write("");
      }
    }
  }

  /**
   * Find the array key for a given package name with a case-insensitive search.
   *
   * @param string $package_name
   *   The package name from composer. This is always already lower case.
   *
   * @return string|null
   *   The string key, or NULL if none was found.
   *
   * @internal
   */
  protected static function findPackageKey($package_name) {
    $package_key = NULL;
    // In most cases the package name is already used as the array key.
    if (isset(static::$packageToCleanup[$package_name])) {
      $package_key = $package_name;
    }
    else {
      // Handle any mismatch in case between the package name and array key.
      // For example, the array key 'mikey179/vfsStream' needs to be found
      // when composer returns a package name of 'mikey179/vfsstream'.
      foreach (static::$packageToCleanup as $key => $dirs) {
        if (strtolower($key) === $package_name) {
          $package_key = $key;
          break;
        }
      }
    }
    return $package_key;
  }

  /**
   * Removes Composer's timeout so that scripts can run indefinitely.
   *
   * @deprecated in drupal:9.5.0 and is removed from drupal:10.0.0. There is no
   *   replacement.
   *
   * @see https://www.drupal.org/node/3260624
   */
  public static function removeTimeout() {
    trigger_error('Calling ' . __METHOD__ . ' from composer.json is deprecated in drupal:9.5.0 and is removed from drupal:10.0.0. There is no replacement. See https://www.drupal.org/node/3260624', E_USER_DEPRECATED);

    ProcessExecutor::setTimeout(0);
  }

  /**
   * Helper method to remove directories and the files they contain.
   *
   * @param string $path
   *   The directory or file to remove. It must exist.
   *
   * @return bool
   *   TRUE on success or FALSE on failure.
   *
   * @internal
   */
  protected static function deleteRecursive($path) {
    if (is_file($path) || is_link($path)) {
      return unlink($path);
    }
    $success = TRUE;
    $dir = dir($path);
    while (($entry = $dir->read()) !== FALSE) {
      if ($entry == '.' || $entry == '..') {
        continue;
      }
      $entry_path = $path . '/' . $entry;
      $success = static::deleteRecursive($entry_path) && $success;
    }
    $dir->close();

    return rmdir($path) && $success;
  }

  /**
   * Fires the drupal-phpunit-upgrade script event if necessary.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   *
   * @internal
   */
  public static function upgradePHPUnit(Event $event) {
    $repository = $event->getComposer()->getRepositoryManager()->getLocalRepository();
    // This is, essentially, a null constraint. We only care whether the package
    // is present in the vendor directory yet, but findPackage() requires it.
    $constraint = new Constraint('>', '');
    $phpunit_package = $repository->findPackage('phpunit/phpunit', $constraint);
    if (!$phpunit_package) {
      // There is nothing to do. The user is probably installing using the
      // --no-dev flag.
      return;
    }

    // If the PHP version is 7.4 or above and PHPUnit is less than version 9
    // call the drupal-phpunit-upgrade script to upgrade PHPUnit.
    if (!static::upgradePHPUnitCheck($phpunit_package->getVersion())) {
      $event->getComposer()
        ->getEventDispatcher()
        ->dispatchScript('drupal-phpunit-upgrade');
    }
  }

  /**
   * Determines if PHPUnit needs to be upgraded.
   *
   * This method is located in this file because it is possible that it is
   * called before the autoloader is available.
   *
   * @param string $phpunit_version
   *   The PHPUnit version string.
   *
   * @return bool
   *   TRUE if the PHPUnit needs to be upgraded, FALSE if not.
   *
   * @internal
   */
  public static function upgradePHPUnitCheck($phpunit_version) {
    return !(version_compare(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, '7.4') >= 0 && version_compare($phpunit_version, '9.0') < 0);
  }

}
