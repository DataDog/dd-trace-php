<?php

namespace Drupal\Tests\taxonomy\Kernel\Plugin\migrate\source\d7;

/**
 * Tests D7 i18n term localized source plugin.
 *
 * @covers \Drupal\taxonomy\Plugin\migrate\source\d7\TermLocalizedTranslation
 * @group taxonomy
 */
class TermLocalizedTranslationTest extends TermTest {

  /**
   * {@inheritdoc}
   */
  public static $modules = ['taxonomy', 'migrate_drupal'];

  /**
   * {@inheritdoc}
   */
  public function providerSource() {
    $tests = parent::providerSource();

    for ($i = 1; $i < 4; $i++) {
      unset($tests[$i]);
    }

    foreach ($tests[0]['source_data']['taxonomy_term_data'] as $key => $value) {
      $tests[0]['source_data']['taxonomy_term_data'][$key]['language'] = 'und';
      $tests[0]['source_data']['taxonomy_term_data'][$key]['i18n_tsid'] = 0;
    }
    // The source data.
    $tests[0]['source_data']['i18n_string'] = [
      [
        'lid' => 6,
        'objectid' => 1,
        'type' => 'term',
        'property' => 'name',
        'objectindex' => '1',
        'format' => 0,
      ],
      [
        'lid' => 7,
        'objectid' => 1,
        'type' => 'term',
        'property' => 'description',
        'objectindex' => '1',
        'format' => 0,
      ],
      [
        'lid' => 8,
        'objectid' => 3,
        'type' => 'term',
        'property' => 'name',
        'objectindex' => '3',
        'format' => 0,
      ],
      [
        'lid' => 9,
        'objectid' => 4,
        'type' => 'term',
        'property' => 'description',
        'objectindex' => '4',
        'format' => 0,
      ],
    ];
    $tests[0]['source_data']['locales_target'] = [
      [
        'lid' => 6,
        'language' => 'fr',
        'translation' => 'fr - name value 1',
        'plid' => 0,
        'plural' => 0,
        'i18n_status' => 0,
      ],
      [
        'lid' => 7,
        'language' => 'fr',
        'translation' => 'fr - description value 1',
        'plid' => 0,
        'plural' => 0,
        'i18n_status' => 0,
      ],
      [
        'lid' => 8,
        'language' => 'zu',
        'translation' => 'zu - name value 3',
        'plid' => 0,
        'plural' => 0,
        'i18n_status' => 0,
      ],
    ];

    // The expected results.
    $tests[0]['expected_data'] = [
      [
        'tid' => 1,
        'vid' => 5,
        'name' => 'name value 1 (name_field)',
        'description' => 'description value 1 (description_field)',
        'weight' => 0,
        'is_container' => '',
        'language' => 'en',
        'i18n_tsid' => '0',
        'machine_name' => 'tags',
        'tdlanguage' => 'und',
        'lid' => '6',
        'property' => 'name',
        'ltlanguage' => 'fr',
        'translation' => 'fr - name value 1',
      ],
      [
        'tid' => 1,
        'vid' => 5,
        'name' => 'name value 1 (name_field)',
        'description' => 'description value 1 (description_field)',
        'weight' => 0,
        'is_container' => '',
        'language' => 'en',
        'i18n_tsid' => '0',
        'machine_name' => 'tags',
        'tdlanguage' => 'und',
        'lid' => '7',
        'property' => 'description',
        'ltlanguage' => 'fr',
        'translation' => 'fr - description value 1',
      ],
      [
        'tid' => 3,
        'vid' => 6,
        'name' => 'name value 3',
        'description' => 'description value 3',
        'weight' => 0,
        'is_container' => '',
        'language' => 'en',
        'i18n_tsid' => '0',
        'machine_name' => 'categories',
        'tdlanguage' => 'und',
        'lid' => '8',
        'property' => 'name',
        'ltlanguage' => 'zu',
        'translation' => 'zu - name value 3',
      ],
    ];

    $tests[0]['expected_count'] = NULL;
    // Empty configuration will return terms for all vocabularies.
    $tests[0]['configuration'] = [];

    return $tests;
  }

}
