<?php

namespace Drupal\Tests\media\Kernel;

use Drupal\entity_test\Entity\EntityTestBundle;
use Drupal\Tests\field\Traits\EntityReferenceTestTrait;

/**
 * @coversDefaultClass \Drupal\media\Plugin\Field\FieldFormatter\MediaThumbnailFormatter
 * @group media
 */
class MediaThumbnailFormatterTest extends MediaKernelTestBase {

  use EntityReferenceTestTrait;

  /**
   * Modules to install.
   *
   * @var array
   */
  protected static $modules = [
    'entity_test',
  ];

  /**
   * Test media reference field name.
   *
   * @var string
   */
  protected $mediaFieldName = 'field_media';

  /**
   * Test entity type id.
   *
   * @var string
   */
  protected $testEntityTypeId = 'entity_test_with_bundle';

  /**
   * Test entity bundle id.
   *
   * @var string
   */
  protected $testEntityBundleId = 'entity_test_bundle';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Create an entity bundle that has a media reference field.
    $entity_test_bundle = EntityTestBundle::create([
      'id' => $this->testEntityBundleId,
    ]);
    $entity_test_bundle->save();
    $this->createEntityReferenceField(
      $this->testEntityTypeId,
      $this->testEntityBundleId,
      $this->mediaFieldName,
      $this->mediaFieldName,
      'media'
    );
  }

  /**
   * Tests the settings summary.
   *
   * @param array $settings
   *   The settings to use for the formatter.
   * @param array $expected_summary
   *   The expected settings summary.
   *
   * @covers ::settingsSummary
   *
   * @dataProvider providerTestSettingsSummary
   */
  public function testSettingsSummary(array $settings, array $expected_summary): void {
    /** @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface $display  */
    $display = \Drupal::service('entity_display.repository')->getViewDisplay($this->testEntityTypeId, $this->testEntityBundleId);
    $display->setComponent($this->mediaFieldName, [
      'type' => 'media_thumbnail',
      'settings' => $settings,
    ]);
    $formatter = $display->getRenderer($this->mediaFieldName);
    $actual_summary = array_map('strval', $formatter->settingsSummary());
    $this->assertSame($expected_summary, $actual_summary);
  }

  /**
   * Data provider for testSettingsSummary().
   *
   * @return array[]
   */
  public function providerTestSettingsSummary(): array {
    return [
      'link to content' => [
        [
          'image_link' => 'content',
        ],
        [
          'Original image',
          'Linked to content',
          'Image loading: lazy',
        ],
      ],
      'link to media' => [
        [
          'image_link' => 'media',
        ],
        [
          'Original image',
          'Image loading: lazy',
          'Linked to media item',
        ],
      ],
      'link to nothing' => [
        [
          'image_link' => '',
        ],
        [
          'Original image',
          'Image loading: lazy',
        ],
      ],
    ];
  }

}
