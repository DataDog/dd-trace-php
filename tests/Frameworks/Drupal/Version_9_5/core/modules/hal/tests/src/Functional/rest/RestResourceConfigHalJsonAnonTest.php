<?php

namespace Drupal\Tests\hal\Functional\rest;

use Drupal\Tests\rest\Functional\AnonResourceTestTrait;
use Drupal\Tests\rest\Functional\Rest\RestResourceConfigResourceTestBase;

/**
 * @group hal
 * @group legacy
 */
class RestResourceConfigHalJsonAnonTest extends RestResourceConfigResourceTestBase {

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
