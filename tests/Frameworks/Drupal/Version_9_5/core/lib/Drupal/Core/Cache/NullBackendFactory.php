<?php

namespace Drupal\Core\Cache;

use Symfony\Component\DependencyInjection\ContainerAwareTrait;

class NullBackendFactory implements CacheFactoryInterface {
    use ContainerAwareTrait;
  /**
   * {@inheritdoc}
   */
  public function get($bin) {
    return new NullBackend($bin);
  }

}
