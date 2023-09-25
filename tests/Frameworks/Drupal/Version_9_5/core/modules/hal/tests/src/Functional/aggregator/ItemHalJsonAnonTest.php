<?php

namespace Drupal\Tests\hal\Functional\aggregator;

use Drupal\Tests\rest\Functional\AnonResourceTestTrait;

/**
 * @group hal
 * @group legacy
 */
class ItemHalJsonAnonTest extends ItemHalJsonTestBase {

  use AnonResourceTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['hal'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $format = 'hal_json';

  /**
   * {@inheritdoc}
   */
  protected static $mimeType = 'application/hal+json';

}
