<?php

namespace Drupal\simpletest;

@trigger_error(__NAMESPACE__ . '\ContentTypeCreationTrait is deprecated in Drupal 8.4.x. Will be removed before Drupal 9.0.0. Use \Drupal\Tests\node\Traits\ContentTypeCreationTrait instead. See https://www.drupal.org/node/2884454.', E_USER_DEPRECATED);

use Drupal\Tests\node\Traits\ContentTypeCreationTrait as BaseContentTypeCreationTrait;

/**
 * Provides methods to create content type from given values.
 *
 * This trait is meant to be used only by test classes.
 *
 * @deprecated in drupal:8.4.0 and is removed from drupal:9.0.0. Use
 *   \Drupal\Tests\node\Traits\ContentTypeCreationTrait instead.
 *
 * @see https://www.drupal.org/node/2884454
 */
trait ContentTypeCreationTrait {

  use BaseContentTypeCreationTrait;

}
