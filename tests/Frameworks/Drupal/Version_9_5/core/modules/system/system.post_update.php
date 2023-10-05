<?php

/**
 * @file
 * Post update functions for System.
 */

use Drupal\Core\Site\Settings;
use Drupal\Core\Config\Entity\ConfigEntityUpdater;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\Display\EntityDisplayInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Extension\Exception\UnknownExtensionException;

/**
 * Implements hook_removed_post_updates().
 */
function system_removed_post_updates() {
  return [
    'system_post_update_recalculate_configuration_entity_dependencies' => '9.0.0',
    'system_post_update_add_region_to_entity_displays' => '9.0.0',
    'system_post_update_hashes_clear_cache' => '9.0.0',
    'system_post_update_timestamp_plugins' => '9.0.0',
    'system_post_update_classy_message_library' => '9.0.0',
    'system_post_update_field_type_plugins' => '9.0.0',
    'system_post_update_field_formatter_entity_schema' => '9.0.0',
    'system_post_update_fix_jquery_extend' => '9.0.0',
    'system_post_update_change_action_plugins' => '9.0.0',
    'system_post_update_change_delete_action_plugins' => '9.0.0',
    'system_post_update_language_item_callback' => '9.0.0',
    'system_post_update_extra_fields' => '9.0.0',
    'system_post_update_states_clear_cache' => '9.0.0',
    'system_post_update_add_expand_all_items_key_in_system_menu_block' => '9.0.0',
    'system_post_update_clear_menu_cache' => '9.0.0',
    'system_post_update_layout_plugin_schema_change' => '9.0.0',
    'system_post_update_entity_reference_autocomplete_match_limit' => '9.0.0',
  ];
}

/**
 * Update all entity form displays that contain extra fields.
 */
function system_post_update_extra_fields_form_display(&$sandbox = NULL) {
  $config_entity_updater = \Drupal::classResolver(ConfigEntityUpdater::class);
  $entity_field_manager = \Drupal::service('entity_field.manager');

  $callback = function (EntityDisplayInterface $display) use ($entity_field_manager) {
    $display_context = $display instanceof EntityViewDisplayInterface ? 'display' : 'form';
    $extra_fields = $entity_field_manager->getExtraFields($display->getTargetEntityTypeId(), $display->getTargetBundle());

    // If any extra fields are used as a component, resave the display with the
    // updated component information.
    $needs_save = FALSE;
    if (!empty($extra_fields[$display_context])) {
      foreach ($extra_fields[$display_context] as $name => $extra_field) {
        if ($component = $display->getComponent($name)) {
          $display->setComponent($name, $component);
          $needs_save = TRUE;
        }
      }
    }
    return $needs_save;
  };

  $config_entity_updater->update($sandbox, 'entity_form_display', $callback);
}

/**
 * Uninstall SimpleTest.
 *
 * @see https://www.drupal.org/project/drupal/issues/3110862
 */
function system_post_update_uninstall_simpletest() {
  \Drupal::service('module_installer')->uninstall(['simpletest']);
}

/**
 * Uninstall entity_reference.
 *
 * @see https://www.drupal.org/project/drupal/issues/3111645
 */
function system_post_update_uninstall_entity_reference_module() {
  \Drupal::service('module_installer')->uninstall(['entity_reference']);
}

/**
 * Remove backwards-compatibility leftovers from entity type definitions.
 */
function system_post_update_entity_revision_metadata_bc_cleanup() {
  /** @var \Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface $last_installed_schema_repository */
  $last_installed_schema_repository = \Drupal::service('entity.last_installed_schema.repository');

  // Get a list of content entity types.
  /** @var \Drupal\Core\Entity\EntityTypeInterface[] $last_installed_definitions */
  $last_installed_definitions = array_filter($last_installed_schema_repository->getLastInstalledDefinitions(), function (EntityTypeInterface $entity_type) {
    return $entity_type instanceof ContentEntityTypeInterface;
  });

  // Remove the '$requiredRevisionMetadataKeys' property for these entity types.
  foreach ($last_installed_definitions as $entity_type) {
    $closure = function (ContentEntityTypeInterface $entity_type) {
      return get_object_vars($entity_type);
    };
    $closure = \Closure::bind($closure, NULL, $entity_type);

    $entity_type_definition = $closure($entity_type);
    unset($entity_type_definition["\x00*\x00requiredRevisionMetadataKeys"]);
    $entity_type = new ContentEntityType($entity_type_definition);

    $last_installed_schema_repository->setLastInstalledDefinition($entity_type);
  }
}

/**
 * Uninstall Classy if it is no longer needed.
 */
function system_post_update_uninstall_classy() {
  /** @var \Drupal\Core\Extension\ThemeInstallerInterface $theme_installer */
  $theme_installer = \Drupal::getContainer()->get('theme_installer');
  try {
    $theme_installer->uninstall(['classy']);
  }
  catch (\InvalidArgumentException | UnknownExtensionException $exception) {
    // Exception is thrown if Classy wasn't installed or if there are themes
    // depending on it.
  }
}

/**
 * Uninstall Stable if it is no longer needed.
 *
 * This needs to run after system_post_update_uninstall_classy(). This will be
 * the case since getAvailableUpdateFunctions() returns an alphabetically sorted
 * list of post_update hooks to be run.
 *
 * @see Drupal\Core\Update\UpdateRegistry::getAvailableUpdateFunctions()
 */
function system_post_update_uninstall_stable() {
  /** @var \Drupal\Core\Extension\ThemeInstallerInterface $theme_installer */
  $theme_installer = \Drupal::getContainer()->get('theme_installer');
  try {
    $theme_installer->uninstall(['stable']);
  }
  catch (\InvalidArgumentException | UnknownExtensionException $exception) {
    // Exception is thrown if Stable wasn't installed or if there are themes
    // depending on it.
  }
}

/**
 * Clear caches due to trustedCallbacks changing in ClaroPreRender.
 */
function system_post_update_claro_dropbutton_variants() {
  // Empty post-update hook.
}

/**
 * Update schema version to integers.
 *
 * @see https://www.drupal.org/project/drupal/issues/3143713
 */
function system_post_update_schema_version_int() {
  $registry = \Drupal::keyValue('system.schema');
  foreach ($registry->getAll() as $name => $schema) {
    if (is_string($schema)) {
      $registry->set($name, (int) $schema);
    }
  }
}

/**
 * Remove obsolete system.rss configuration.
 */
function system_post_update_delete_rss_settings() {
  \Drupal::configFactory()->getEditable('system.rss')
    ->clear('channel')
    ->clear('items.limit')
    ->clear('langcode')
    ->save();
}

/**
 * Drop the 'all' index on the 'key_value_expire' table.
 */
function system_post_update_remove_key_value_expire_all_index() {
  $schema = \Drupal::database()->schema();
  if ($schema->tableExists('key_value_expire')) {
    $schema->dropIndex('key_value_expire', 'all');
  }
}

/**
 * Add new security advisory retrieval settings.
 */
function system_post_update_service_advisory_settings() {
  $config = \Drupal::configFactory()->getEditable('system.advisories');
  $config->set('interval_hours', 6)->set('enabled', TRUE)->save();
}

/**
 * Remove obsolete system.authorize configuration.
 */
function system_post_update_delete_authorize_settings() {
  \Drupal::configFactory()->getEditable('system.authorize')->delete();
}

/**
 * Sort all configuration according to its schema.
 */
function system_post_update_sort_all_config(&$sandbox) {
  $factory = \Drupal::configFactory();
  $iteration_size = Settings::get('entity_update_batch_size', 50);

  if (empty($sandbox['progress'])) {
    $sandbox['progress'] = 0;
    $sandbox['all_config_names'] = $factory->listAll();
    $sandbox['max'] = count($sandbox['all_config_names']);
  }

  $start = $sandbox['progress'];
  $end = min($sandbox['max'], $start + $iteration_size);
  for ($i = $start; $i < $end; $i++) {
    try {
      $factory->getEditable($sandbox['all_config_names'][$i])->save();
    }
    catch (\Exception $e) {
      watchdog_exception('system', $e);
    }
  }

  if ($sandbox['max'] > 0 && $end < $sandbox['max']) {
    $sandbox['progress'] = $end;
    $sandbox['#finished'] = ($end - 1) / $sandbox['max'];
  }
  else {
    $sandbox['#finished'] = 1;
  }
}

/**
 * Enable the modules that are providing the listed database drivers.
 */
function system_post_update_enable_provider_database_driver() {
  $modules_to_install = [];
  foreach (Database::getAllConnectionInfo() as $targets) {
    foreach ($targets as $target) {
      // Provider determination taken from Connection::getProvider().
      [$first, $second] = explode('\\', $target['namespace'] ?? '', 3);
      $provider = ($first === 'Drupal' && strtolower($second) === $second) ? $second : 'core';
      if ($provider !== 'core' && !\Drupal::moduleHandler()->moduleExists($provider)) {
        $autoload = $target['autoload'] ?? '';
        // We are only enabling the module for database drivers that are
        // provided by a module.
        if (str_contains($autoload, 'src/Driver/Database/')) {
          $modules_to_install[$provider] = TRUE;
        }
      }
    }
  }

  if ($modules_to_install !== []) {
    \Drupal::service('module_installer')->install(array_keys($modules_to_install));
  }
}
