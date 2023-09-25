<?php

namespace Drupal\Tests\migrate_drupal\Kernel\Plugin\migrate\source\d6;

use Drupal\Tests\migrate\Kernel\MigrateSqlSourceTestBase;

/**
 * Tests the variable source plugin.
 *
 * @covers \Drupal\migrate_drupal\Plugin\migrate\source\d6\i18nVariable
 *
 * @group migrate_drupal
 * @group legacy
 */
class i18nVariableTest extends MigrateSqlSourceTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = ['migrate_drupal'];

  /**
   * {@inheritdoc}
   *
   * @dataProvider providerSource
   * @requires extension pdo_sqlite
   * @expectedDeprecation The Drupal\migrate_drupal\Plugin\migrate\source\d6\i18nVariable is deprecated in Drupal 8.4.0 and will be removed before Drupal 9.0.0. Instead, use Drupal\migrate_drupal\Plugin\migrate\source\d6\VariableTranslation
   */
  public function testSource(array $source_data, array $expected_data, $expected_count = NULL, array $configuration = [], $high_water = NULL) {
    parent::testSource($source_data, $expected_data, $expected_count, $configuration, $high_water);
  }

  /**
   * {@inheritdoc}
   */
  public function providerSource() {
    $tests = [];

    // The source data.
    $tests[0]['source_data']['i18n_variable'] = [
      [
        'name' => 'site_slogan',
        'language' => 'fr',
        'value' => 's:19:"Migrate est génial";',
      ],
      [
        'name' => 'site_name',
        'language' => 'fr',
        'value' => 's:11:"nom de site";',
      ],
      [
        'name' => 'site_slogan',
        'language' => 'mi',
        'value' => 's:19:"Ko whakamataku heke";',
      ],
      [
        'name' => 'site_name',
        'language' => 'mi',
        'value' => 's:9:"ingoa_pae";',
      ],
    ];

    // The expected results.
    $tests[0]['expected_data'] = [
      [
        'language' => 'fr',
        'site_slogan' => 'Migrate est génial',
        'site_name' => 'nom de site',
      ],
      [
        'language' => 'mi',
        'site_slogan' => 'Ko whakamataku heke',
        'site_name' => 'ingoa_pae',
      ],
    ];

    // The expected count.
    $tests[0]['expected_count'] = NULL;

    // The migration configuration.
    $tests[0]['configuration']['variables'] = [
      'site_slogan',
      'site_name',
    ];

    return $tests;
  }

}
