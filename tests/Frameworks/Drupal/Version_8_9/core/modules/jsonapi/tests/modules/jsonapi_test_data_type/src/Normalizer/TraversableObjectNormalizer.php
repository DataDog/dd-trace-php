<?php

namespace Drupal\jsonapi_test_data_type\Normalizer;

use Drupal\jsonapi_test_data_type\TraversableObject;
use Drupal\serialization\Normalizer\NormalizerBase;

/**
 * Normalizes TraversableObject.
 */
class TraversableObjectNormalizer extends NormalizerBase {

  /**
   * {@inheritdoc}
   */
  protected $supportedInterfaceOrClass = TraversableObject::class;

  /**
   * {@inheritdoc}
   */
  public function normalize($object, $format = NULL, array $context = []) {
    return $object->property;
  }

}
