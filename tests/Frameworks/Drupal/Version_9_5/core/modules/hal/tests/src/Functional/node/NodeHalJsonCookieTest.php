<?php

namespace Drupal\Tests\hal\Functional\node;

use Drupal\Tests\rest\Functional\CookieResourceTestTrait;

/**
 * @group hal
 * @group legacy
 */
class NodeHalJsonCookieTest extends NodeHalJsonAnonTest {

  use CookieResourceTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $auth = 'cookie';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

}
