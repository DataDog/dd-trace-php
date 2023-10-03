<?php

namespace Drupal\FunctionalTests\Rest;

use Drupal\Core\Datetime\Entity\DateFormat;
use Drupal\Tests\rest\Functional\EntityResource\ConfigEntityResourceTestBase;

/**
 * ResourceTestBase for DateFormat entity.
 */
abstract class DateFormatResourceTestBase extends ConfigEntityResourceTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [];

  /**
   * {@inheritdoc}
   */
  protected static $entityTypeId = 'date_format';

  /**
   * The DateFormat entity.
   *
   * @var \Drupal\Core\Datetime\DateFormatInterface
   */
  protected $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUpAuthorization($method) {
    $this->grantPermissionsToTestedRole(['administer site configuration']);
  }

  /**
   * {@inheritdoc}
   */
  protected function createEntity() {
    // Create a date format.
    $date_format = DateFormat::create([
      'id' => 'llama',
      'label' => 'Llama',
      'pattern' => 'F d, Y',
    ]);

    $date_format->save();

    return $date_format;
  }

  /**
   * {@inheritdoc}
   */
  protected function getExpectedNormalizedEntity() {
    return [
      'dependencies' => [],
      'id' => 'llama',
      'label' => 'Llama',
      'langcode' => 'en',
      'locked' => FALSE,
      'pattern' => 'F d, Y',
      'status' => TRUE,
      'uuid' => $this->entity->uuid(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getNormalizedPostEntity() {
    // @todo Update in https://www.drupal.org/node/2300677.
    return [];
  }

}
