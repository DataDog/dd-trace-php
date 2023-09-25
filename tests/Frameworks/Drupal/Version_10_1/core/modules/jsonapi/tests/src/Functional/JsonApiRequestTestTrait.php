<?php

namespace Drupal\Tests\jsonapi\Functional;

use Drupal\Tests\ApiRequestTrait;

/**
 * Boilerplate for JSON:API Functional tests' HTTP requests.
 *
 * @internal
 */
trait JsonApiRequestTestTrait {
  use ApiRequestTrait {
    makeApiRequest as request;
  }

}
