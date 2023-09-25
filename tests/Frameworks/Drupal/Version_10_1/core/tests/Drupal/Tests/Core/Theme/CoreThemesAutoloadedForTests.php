<?php

namespace Drupal\Tests\Core\Theme;

use Drupal\Tests\UnitTestCase;
use Drupal\claro\ClaroPreRender;

/**
 * Confirms that core/themes is autoloaded for tests.
 *
 * @group Theme
 */
class CoreThemesAutoloadedForTests extends UnitTestCase {

  /**
   * Confirms that core/themes is autoloaded for tests.
   */
  public function testCoreThemesAutoloadedForTests() {
    $this->assertTrue(class_exists(ClaroPreRender::class), 'core/themes (ClaroPreRender) is registered with the tests autoloader');
  }

}
