<?php

namespace Drupal\block\Plugin\migrate\process;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateLookupInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\Plugin\MigrateProcessInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @MigrateProcessPlugin(
 *   id = "block_visibility"
 * )
 */
class BlockVisibility extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The migration process plugin.
   *
   * The plugin is configured for lookups in the d6_user_role and d7_user_role
   * migrations.
   *
   * @var \Drupal\migrate\Plugin\MigrateProcessInterface
   *
   * @deprecated in drupal:8.8.x and is removed from drupal:9.0.0. Use
   *   the migrate.lookup service instead.
   *
   * @see https://www.drupal.org/node/3047268
   */
  protected $migrationPlugin;

  /**
   * The migrate lookup service.
   *
   * @var \Drupal\migrate\MigrateLookupInterface
   */
  protected $migrateLookup;

  /**
   * Whether or not to skip blocks that use PHP for visibility. Only applies
   * if the PHP module is not enabled.
   *
   * @var bool
   */
  protected $skipPHP = FALSE;

  /**
   * Constructs a BlockVisibility object.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\migrate\MigrateLookupInterface $migrate_lookup
   *   The migrate lookup service.
   */
  // @codingStandardsIgnoreLine
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ModuleHandlerInterface $module_handler, $migrate_lookup) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    if ($migrate_lookup instanceof MigrateProcessInterface) {
      @trigger_error('Passing a migration process plugin as the fifth argument to ' . __METHOD__ . ' is deprecated in drupal:8.8.0 and will throw an error in drupal:9.0.0. Pass the migrate.lookup service instead. See https://www.drupal.org/node/3047268', E_USER_DEPRECATED);
      $this->migrationPlugin = $migrate_lookup;
      $migrate_lookup = \Drupal::service('migrate.lookup');
    }
    elseif (!$migrate_lookup instanceof MigrateLookupInterface) {
      throw new \InvalidArgumentException("The fifth argument to " . __METHOD__ . " must be an instance of MigrateLookupInterface.");
    }
    $this->moduleHandler = $module_handler;
    $this->migrateLookup = $migrate_lookup;

    if (isset($configuration['skip_php'])) {
      $this->skipPHP = $configuration['skip_php'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('module_handler'),
      $container->get('migrate.lookup')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    list($old_visibility, $pages, $roles) = $value;

    $visibility = [];

    // If the block is assigned to specific roles, add the user_role condition.
    if ($roles) {
      $visibility['user_role'] = [
        'id' => 'user_role',
        'roles' => [],
        'context_mapping' => [
          'user' => '@user.current_user_context:current_user',
        ],
        'negate' => FALSE,
      ];

      foreach ($roles as $key => $role_id) {
        // This BC layer is included because if the plugin constructor was
        // called in the legacy way with a migration_lookup process plugin, it
        // may have been preconfigured with a different migration to look up
        // against. While this is unlikely, for maximum BC we will continue to
        // use the plugin to do the lookup if it is provided, and support for
        // this will be removed in Drupal 9.
        if ($this->migrationPlugin) {
          $roles[$key] = $this->migrationPlugin->transform($role_id, $migrate_executable, $row, $destination_property);
        }
        else {
          $lookup_result = $this->migrateLookup->lookup(['d6_user_role', 'd7_user_role'], [$role_id]);
          if ($lookup_result) {
            $roles[$key] = $lookup_result[0]['id'];
          }
        }

      }
      $visibility['user_role']['roles'] = array_combine($roles, $roles);
    }

    if ($pages) {
      // 2 == BLOCK_VISIBILITY_PHP in Drupal 6 and 7.
      if ($old_visibility == 2) {
        // If the PHP module is present, migrate the visibility code unaltered.
        if ($this->moduleHandler->moduleExists('php')) {
          $visibility['php'] = [
            'id' => 'php',
            // PHP code visibility could not be negated in Drupal 6 or 7.
            'negate' => FALSE,
            'php' => $pages,
          ];
        }
        // Skip the row if we're configured to. If not, we don't need to do
        // anything else -- the block will simply have no PHP or request_path
        // visibility configuration.
        elseif ($this->skipPHP) {
          throw new MigrateSkipRowException(sprintf("The block with bid '%d' from module '%s' will have no PHP or request_path visibility configuration.", $row->getSourceProperty('bid'), $row->getSourceProperty('module'), $destination_property));
        }
      }
      else {
        $paths = preg_split("(\r\n?|\n)", $pages);
        foreach ($paths as $key => $path) {
          $paths[$key] = $path === '<front>' ? $path : '/' . ltrim($path, '/');
        }
        $visibility['request_path'] = [
          'id' => 'request_path',
          'negate' => !$old_visibility,
          'pages' => implode("\n", $paths),
        ];
      }
    }

    return $visibility;
  }

}
