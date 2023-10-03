<?php

namespace Drupal\Tests\jsonapi\Functional;

use Drupal\comment\Entity\CommentType;
use Drupal\Core\Url;

/**
 * JSON:API integration test for the "CommentType" config entity type.
 *
 * @group jsonapi
 */
class CommentTypeTest extends ResourceTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = ['node', 'comment'];

  /**
   * {@inheritdoc}
   */
  protected static $entityTypeId = 'comment_type';

  /**
   * {@inheritdoc}
   */
  protected static $resourceTypeName = 'comment_type--comment_type';

  /**
   * {@inheritdoc}
   *
   * @var \Drupal\comment\CommentTypeInterface
   */
  protected $entity;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUpAuthorization($method) {
    $this->grantPermissionsToTestedRole(['administer comment types']);
  }

  /**
   * {@inheritdoc}
   */
  protected function createEntity() {
    // Create a "Camelids" comment type.
    $camelids = CommentType::create([
      'id' => 'camelids',
      'label' => 'Camelids',
      'description' => 'Camelids are large, strictly herbivorous animals with slender necks and long legs.',
      'target_entity_type_id' => 'node',
    ]);

    $camelids->save();

    return $camelids;
  }

  /**
   * {@inheritdoc}
   */
  protected function getExpectedDocument() {
    $self_url = Url::fromUri('base:/jsonapi/comment_type/comment_type/' . $this->entity->uuid())->setAbsolute()->toString(TRUE)->getGeneratedUrl();
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
        'self' => ['href' => $self_url],
      ],
      'data' => [
        'id' => $this->entity->uuid(),
        'type' => 'comment_type--comment_type',
        'links' => [
          'self' => ['href' => $self_url],
        ],
        'attributes' => [
          'dependencies' => [],
          'description' => 'Camelids are large, strictly herbivorous animals with slender necks and long legs.',
          'label' => 'Camelids',
          'langcode' => 'en',
          'status' => TRUE,
          'target_entity_type_id' => 'node',
          'drupal_internal__id' => 'camelids',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getPostDocument() {
    // @todo Update in https://www.drupal.org/node/2300677.
  }

}
