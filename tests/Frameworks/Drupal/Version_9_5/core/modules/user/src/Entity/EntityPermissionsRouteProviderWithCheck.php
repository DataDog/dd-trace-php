<?php

namespace Drupal\user\Entity;

use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\Routing\Route;

/**
 * Provides routes for the entity permissions form with a custom access check.
 *
 * Use this class or EntityPermissionsRouteProvider as a route provider for an
 * entity type such as BlockContentType. Either one will provide routes for the
 * entity permissions form. This class provides a custom access check: it denies
 * access if there are no entity-specific permissions. If you know that each
 * entity has permissions, or if the check is too expensive, then use
 * EntityPermissionsRouteProvider instead of this class.
 */
class EntityPermissionsRouteProviderWithCheck extends EntityPermissionsRouteProvider {

  /**
   * {@inheritdoc}
   */
  protected function getEntityPermissionsRoute(EntityTypeInterface $entity_type): ?Route {
    $route = parent::getEntityPermissionsRoute($entity_type);
    if ($route) {
      $route->setRequirement('_custom_access', '\Drupal\user\Form\EntityPermissionsForm::access');
    }
    return $route;
  }

}
