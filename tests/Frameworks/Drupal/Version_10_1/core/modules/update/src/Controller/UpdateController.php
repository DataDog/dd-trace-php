<?php

namespace Drupal\update\Controller;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\update\UpdateFetcherInterface;
use Drupal\update\UpdateManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller routines for update routes.
 */
class UpdateController extends ControllerBase {

  /**
   * Update manager service.
   *
   * @var \Drupal\update\UpdateManagerInterface
   */
  protected $updateManager;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs update status data.
   *
   * @param \Drupal\update\UpdateManagerInterface $update_manager
   *   Update Manager Service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(UpdateManagerInterface $update_manager, RendererInterface $renderer) {
    $this->updateManager = $update_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('update.manager'),
      $container->get('renderer')
    );
  }

  /**
   * Returns a page about the update status of projects.
   *
   * @return array
   *   A build array with the update status of projects.
   */
  public function updateStatus() {
    $build = [
      '#theme' => 'update_report',
    ];
    if ($available = update_get_available(TRUE)) {
      $this->moduleHandler()->loadInclude('update', 'compare.inc');
      $build['#data'] = update_calculate_project_data($available);

      // @todo Consider using 'fetch_failures' from the 'update' collection
      // in the key_value_expire service for this?
      $fetch_failed = FALSE;
      foreach ($build['#data'] as $project) {
        if ($project['status'] === UpdateFetcherInterface::NOT_FETCHED) {
          $fetch_failed = TRUE;
          break;
        }
      }
      if ($fetch_failed) {
        $message = ['#theme' => 'update_fetch_error_message'];
        $this->messenger()->addError($this->renderer->renderPlain($message));
      }
    }
    return $build;
  }

  /**
   * Manually checks the update status without the use of cron.
   */
  public function updateStatusManually() {
    $this->updateManager->refreshUpdateData();
    $batch_builder = (new BatchBuilder())
      ->setTitle(t('Checking available update data'))
      ->addOperation([$this->updateManager, 'fetchDataBatch'], [])
      ->setProgressMessage(t('Trying to check available update data ...'))
      ->setErrorMessage(t('Error checking available update data.'))
      ->setFinishCallback('update_fetch_data_finished');
    batch_set($batch_builder->toArray());
    return batch_process('admin/reports/updates');
  }

}
