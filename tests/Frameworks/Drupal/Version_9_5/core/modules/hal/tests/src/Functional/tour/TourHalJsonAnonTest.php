<?php

namespace Drupal\Tests\hal\Functional\tour;

use Drupal\Tests\rest\Functional\AnonResourceTestTrait;
use Drupal\Tests\tour\Functional\Rest\TourResourceTestBase;

/**
 * @group hal
 * @group legacy
 */
class TourHalJsonAnonTest extends TourResourceTestBase {

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
