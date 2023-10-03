<?php

namespace Drupal\Tests\views\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\views\ViewsConfigUpdater;

/**
 * @coversDefaultClass \Drupal\views\ViewsConfigUpdater
 *
 * @group Views
 * @group legacy
 */
class ViewsConfigUpdaterTest extends ViewsKernelTestBase {

  /**
   * The views config updater.
   *
   * @var \Drupal\views\ViewsConfigUpdater
   */
  protected $configUpdater;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'views_config_entity_test',
    'field',
    'file',
    'image',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp($import_test_views = TRUE): void {
    parent::setUp();

    $this->configUpdater = $this->container
      ->get('class_resolver')
      ->getInstanceFromDefinition(ViewsConfigUpdater::class);

    FieldStorageConfig::create([
      'field_name' => 'user_picture',
      'entity_type' => 'user',
      'type' => 'image',
    ])->save();
    FieldConfig::create([
      'entity_type' => 'user',
      'field_name' => 'user_picture',
      'file_directory' => 'pictures/[date:custom:Y]-[date:custom:m]',
      'bundle' => 'user',
    ])->save();
  }

  /**
   * Loads a test view.
   *
   * @param string $view_id
   *   The view config ID.
   *
   * @return \Drupal\views\ViewEntityInterface
   *   A view entity object.
   */
  protected function loadTestView($view_id) {
    // We just instantiate the test view from the raw configuration, as it may
    // not be possible to save it, due to its faulty schema.
    $config_dir = $this->getModulePath('views') . '/tests/fixtures/update';
    $file_storage = new FileStorage($config_dir);
    $values = $file_storage->read($view_id);
    /** @var \Drupal\views\ViewEntityInterface $test_view */
    $test_view = $this->container
      ->get('entity_type.manager')
      ->getStorage('view')
      ->create($values);
    return $test_view;
  }

  /**
   * @covers ::needsEntityLinkUrlUpdate
   */
  public function testNeedsEntityLinkUrlUpdate() {
    $test_view = $this->loadTestView('views.view.node_link_update_test');
    $this->configUpdater->setDeprecationsEnabled(FALSE);
    $needs_update = $this->configUpdater->needsEntityLinkUrlUpdate($test_view);
    $this->assertTrue($needs_update);
  }

  /**
   * @covers ::needsEntityLinkUrlUpdate
   */
  public function testNeedsEntityLinkUrlUpdateDeprecation() {
    $this->expectDeprecation('The entity link url update for the "node_link_update_test" view is deprecated in drupal:9.0.0 and is removed from drupal:10.0.0. Module-provided Views configuration should be updated to accommodate the changes described at https://www.drupal.org/node/2857891.');
    $test_view = $this->loadTestView('views.view.node_link_update_test');
    $needs_update = $this->configUpdater->needsEntityLinkUrlUpdate($test_view);
    $this->assertTrue($needs_update);
  }

  /**
   * @covers ::needsOperatorDefaultsUpdate
   */
  public function testNeedsOperatorUpdateDefaults() {
    $test_view = $this->loadTestView('views.view.test_exposed_filters');
    $this->configUpdater->setDeprecationsEnabled(FALSE);
    $needs_update = $this->configUpdater->needsOperatorDefaultsUpdate($test_view);
    $this->assertTrue($needs_update);
  }

  /**
   * @covers ::needsOperatorDefaultsUpdate
   */
  public function testNeedsOperatorDefaultsUpdateDeprecation() {
    $this->expectDeprecation('The operator defaults update for the "test_exposed_filters" view is deprecated in drupal:9.0.0 and is removed from drupal:10.0.0. Module-provided Views configuration should be updated to accommodate the changes described at https://www.drupal.org/node/2869168.');
    $test_view = $this->loadTestView('views.view.test_exposed_filters');
    $needs_update = $this->configUpdater->needsOperatorDefaultsUpdate($test_view);
    $this->assertTrue($needs_update);
  }

  /**
   * @covers ::needsImageLazyLoadFieldUpdate
   */
  public function testNeedsImageLazyLoadFieldUpdate() {
    $test_view = $this->loadTestView('views.view.test_user_multi_value');
    $needs_update = $this->configUpdater->needsImageLazyLoadFieldUpdate($test_view);
    $this->assertTrue($needs_update);
  }

  /**
   * @covers ::needsMultivalueBaseFieldUpdate
   */
  public function testNeedsFieldNamesForMultivalueBaseFieldsUpdate() {
    $test_view = $this->loadTestView('views.view.test_user_multi_value');
    $this->configUpdater->setDeprecationsEnabled(FALSE);
    $needs_update = $this->configUpdater->needsMultivalueBaseFieldUpdate($test_view);
    $this->assertTrue($needs_update);
  }

  /**
   * @covers ::needsMultivalueBaseFieldUpdate
   */
  public function testNeedsFieldNamesForMultivalueBaseUpdateFieldsDeprecation() {
    $this->expectDeprecation('The multivalue base field update for the "test_user_multi_value" view is deprecated in drupal:9.0.0 and is removed from drupal:10.0.0. Module-provided Views configuration should be updated to accommodate the changes described at https://www.drupal.org/node/2900684.');
    $test_view = $this->loadTestView('views.view.test_user_multi_value');
    $needs_update = $this->configUpdater->needsMultivalueBaseFieldUpdate($test_view);
    $this->assertTrue($needs_update);
  }

  /**
   * @covers ::updateAll
   */
  public function testUpdateAll() {
    $this->expectDeprecation('The entity link url update for the "node_link_update_test" view is deprecated in drupal:9.0.0 and is removed from drupal:10.0.0. Module-provided Views configuration should be updated to accommodate the changes described at https://www.drupal.org/node/2857891.');
    $this->expectDeprecation('The operator defaults update for the "test_exposed_filters" view is deprecated in drupal:9.0.0 and is removed from drupal:10.0.0. Module-provided Views configuration should be updated to accommodate the changes described at https://www.drupal.org/node/2869168.');
    $this->expectDeprecation('The multivalue base field update for the "test_user_multi_value" view is deprecated in drupal:9.0.0 and is removed from drupal:10.0.0. Module-provided Views configuration should be updated to accommodate the changes described at https://www.drupal.org/node/2900684.');
    $view_ids = [
      'views.view.node_link_update_test',
      'views.view.test_exposed_filters',
      'views.view.test_user_multi_value',
    ];

    foreach ($view_ids as $view_id) {
      $test_view = $this->loadTestView($view_id);
      $this->assertTrue($this->configUpdater->updateAll($test_view), "View $view_id should be updated.");
    }

    // @todo Improve this in https://www.drupal.org/node/3121008.
  }

}
