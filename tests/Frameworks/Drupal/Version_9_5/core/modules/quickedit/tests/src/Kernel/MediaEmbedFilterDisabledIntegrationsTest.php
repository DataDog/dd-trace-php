<?php

namespace Drupal\Tests\quickedit\Kernel;

use Drupal\Tests\media\Kernel\MediaEmbedFilterTestBase;

/**
 * Tests that media embed disables certain integrations.
 *
 * @coversDefaultClass \Drupal\media\Plugin\Filter\MediaEmbed
 * @group quickedit
 * @group legacy
 */
class MediaEmbedFilterDisabledIntegrationsTest extends MediaEmbedFilterTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'contextual',
    'quickedit',
    // @see media_test_embed_entity_view_alter()
    'media_test_embed',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->container->get('current_user')
      ->addRole($this->drupalCreateRole([
        'access contextual links',
        'access in-place editing',
      ]));
  }

  /**
   * @covers ::renderMedia
   * @covers ::disableContextualLinks
   */
  public function testDisabledIntegrations() {
    $text = $this->createEmbedCode([
      'data-entity-type' => 'media',
      'data-entity-uuid' => static::EMBEDDED_ENTITY_UUID,
    ]);

    $this->applyFilter($text);
    $this->assertCount(1, $this->cssSelect('div[data-media-embed-test-view-mode]'));
    $this->assertCount(0, $this->cssSelect('div[data-media-embed-test-view-mode][data-quickedit-entity-id]'));
  }

}
