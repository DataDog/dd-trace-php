<?php

namespace Drupal\Tests\hal\Functional\block_content;

use Drupal\Tests\block_content\Functional\Rest\BlockContentTypeResourceTestBase;
use Drupal\Tests\rest\Functional\BasicAuthResourceTestTrait;

/**
 * @group hal
 * @group legacy
 */
class BlockContentTypeHalJsonBasicAuthTest extends BlockContentTypeResourceTestBase {

  use BasicAuthResourceTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['hal', 'basic_auth'];

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

  /**
   * {@inheritdoc}
   */
  protected static $auth = 'basic_auth';

}
