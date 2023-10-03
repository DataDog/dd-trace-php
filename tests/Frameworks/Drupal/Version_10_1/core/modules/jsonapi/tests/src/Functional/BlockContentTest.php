<?php

namespace Drupal\Tests\jsonapi\Functional;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Url;
use Drupal\Tests\jsonapi\Traits\CommonCollectionFilterAccessTestPatternsTrait;

/**
 * JSON:API integration test for the "BlockContent" content entity type.
 *
 * @group jsonapi
 */
class BlockContentTest extends ResourceTestBase {

  use CommonCollectionFilterAccessTestPatternsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block_content'];

  /**
   * {@inheritdoc}
   */
  protected static $entityTypeId = 'block_content';

  /**
   * {@inheritdoc}
   */
  protected static $resourceTypeName = 'block_content--basic';

  /**
   * {@inheritdoc}
   */
  protected static $resourceTypeIsVersionable = TRUE;

  /**
   * {@inheritdoc}
   */
  protected static $newRevisionsShouldBeAutomatic = TRUE;

  /**
   * {@inheritdoc}
   *
   * @var \Drupal\block_content\BlockContentInterface
   */
  protected $entity;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $patchProtectedFieldNames = [
    'changed' => NULL,
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUpAuthorization($method) {
    switch ($method) {
      case 'GET':
      case 'PATCH':
        $this->grantPermissionsToTestedRole([
          'access block library',
          'administer block types',
          'administer block content',
        ]);
        break;

      case 'POST':
        $this->grantPermissionsToTestedRole(['access block library', 'create basic block content']);
        break;

      case 'DELETE':
        $this->grantPermissionsToTestedRole(['access block library', 'delete any basic block content']);
        break;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createEntity() {
    if (!BlockContentType::load('basic')) {
      $block_content_type = BlockContentType::create([
        'id' => 'basic',
        'label' => 'basic',
        'revision' => TRUE,
      ]);
      $block_content_type->save();
      block_content_add_body_field($block_content_type->id());
    }

    // Create a "Llama" content block.
    $block_content = BlockContent::create([
      'info' => 'Llama',
      'type' => 'basic',
      'body' => [
        'value' => 'The name "llama" was adopted by European settlers from native Peruvians.',
        'format' => 'plain_text',
      ],
    ])
      ->setUnpublished();
    $block_content->save();
    return $block_content;
  }

  /**
   * {@inheritdoc}
   */
  protected function getExpectedDocument() {
    $base_url = Url::fromUri('base:/jsonapi/block_content/basic/' . $this->entity->uuid())->setAbsolute();
    $self_url = clone $base_url;
    $version_identifier = 'id:' . $this->entity->getRevisionId();
    $self_url = $self_url->setOption('query', ['resourceVersion' => $version_identifier]);
    $version_query_string = '?resourceVersion=' . urlencode($version_identifier);
    return [
      'jsonapi' => [
        'meta' => [
          'links' => [
            'self' => ['href' => 'http://jsonapi.org/format/1.0/'],
          ],
        ],
        'version' => '1.0',
      ],
      'links' => [
        'self' => ['href' => $base_url->toString()],
      ],
      'data' => [
        'id' => $this->entity->uuid(),
        'type' => 'block_content--basic',
        'links' => [
          'self' => ['href' => $self_url->toString()],
        ],
        'attributes' => [
          'body' => [
            'value' => 'The name "llama" was adopted by European settlers from native Peruvians.',
            'format' => 'plain_text',
            'summary' => NULL,
            'processed' => "<p>The name &quot;llama&quot; was adopted by European settlers from native Peruvians.</p>\n",
          ],
          'changed' => (new \DateTime())->setTimestamp($this->entity->getChangedTime())->setTimezone(new \DateTimeZone('UTC'))->format(\DateTime::RFC3339),
          'info' => 'Llama',
          'revision_log' => NULL,
          'revision_created' => (new \DateTime())->setTimestamp($this->entity->getRevisionCreationTime())->setTimezone(new \DateTimeZone('UTC'))->format(\DateTime::RFC3339),
          'revision_translation_affected' => TRUE,
          'status' => FALSE,
          'langcode' => 'en',
          'default_langcode' => TRUE,
          'drupal_internal__id' => 1,
          'drupal_internal__revision_id' => 1,
          'reusable' => TRUE,
        ],
        'relationships' => [
          'block_content_type' => [
            'data' => [
              'id' => BlockContentType::load('basic')->uuid(),
              'meta' => [
                'drupal_internal__target_id' => 'basic',
              ],
              'type' => 'block_content_type--block_content_type',
            ],
            'links' => [
              'related' => ['href' => $base_url->toString() . '/block_content_type' . $version_query_string],
              'self' => ['href' => $base_url->toString() . '/relationships/block_content_type' . $version_query_string],
            ],
          ],
          'revision_user' => [
            'data' => NULL,
            'links' => [
              'related' => ['href' => $base_url->toString() . '/revision_user' . $version_query_string],
              'self' => ['href' => $base_url->toString() . '/relationships/revision_user' . $version_query_string],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getPostDocument() {
    return [
      'data' => [
        'type' => 'block_content--basic',
        'attributes' => [
          'info' => 'Dramallama',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getExpectedUnauthorizedAccessMessage($method) {
    return match ($method) {
      'GET' => "The 'access block library' permission is required.",
      'PATCH' => "The following permissions are required: 'access block library' AND 'edit any basic block content'.",
      'POST' => "The following permissions are required: 'create basic block content' AND 'access block library'.",
      'DELETE' => "The following permissions are required: 'access block library' AND 'delete any basic block content'.",
      default => parent::getExpectedUnauthorizedAccessMessage($method),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function getExpectedUnauthorizedAccessCacheability() {
    // @see \Drupal\block_content\BlockContentAccessControlHandler()
    return parent::getExpectedUnauthorizedAccessCacheability()
      ->addCacheTags(['block_content:1']);
  }

  /**
   * {@inheritdoc}
   */
  protected function getExpectedCacheTags(array $sparse_fieldset = NULL) {
    $tags = parent::getExpectedCacheTags($sparse_fieldset);
    if ($sparse_fieldset === NULL || in_array('body', $sparse_fieldset)) {
      $tags = Cache::mergeTags($tags, ['config:filter.format.plain_text']);
    }
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  protected function getExpectedCacheContexts(array $sparse_fieldset = NULL) {
    $contexts = parent::getExpectedCacheContexts($sparse_fieldset);
    if ($sparse_fieldset === NULL || in_array('body', $sparse_fieldset)) {
      $contexts = Cache::mergeContexts($contexts, ['languages:language_interface', 'theme']);
    }
    return $contexts;
  }

  /**
   * {@inheritdoc}
   */
  public function testCollectionFilterAccess() {
    $this->entity->setPublished()->save();
    $this->doTestCollectionFilterAccessForPublishableEntities('info', NULL, 'administer block content');
  }

}
