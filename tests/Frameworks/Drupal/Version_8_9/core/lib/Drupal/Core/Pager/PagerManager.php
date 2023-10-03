<?php

namespace Drupal\Core\Pager;

use Drupal\Component\Utility\DeprecatedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;

/**
 * Provides a manager for pagers.
 *
 * Pagers are cached, and can be retrieved when rendering.
 */
class PagerManager implements PagerManagerInterface {

  use DependencySerializationTrait;

  /**
   * The pager parameters.
   *
   * @var \Drupal\Core\Pager\PagerParametersInterface
   */
  protected $pagerParams;

  /**
   * An associative array of pagers.
   *
   * Implemented as an array consisting of:
   *   - key: the element id integer.
   *   - value: a \Drupal\Core\Pager\Pager.
   *
   * @var array
   */
  protected $pagers;

  /**
   * Construct a PagerManager object.
   *
   * @param \Drupal\Core\Pager\PagerParametersInterface $pager_params
   *   The pager parameters.
   */
  public function __construct(PagerParametersInterface $pager_params) {
    $this->pagerParams = $pager_params;
  }

  /**
   * {@inheritdoc}
   */
  public function createPager($total, $limit, $element = 0) {
    $currentPage = $this->pagerParams->findPage($element);
    $pager = new Pager($total, $limit, $currentPage);
    $this->setPager($pager, $element);
    return $pager;
  }

  /**
   * {@inheritdoc}
   */
  public function getPager($element = 0) {
    return isset($this->pagers[$element]) ? $this->pagers[$element] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getUpdatedParameters(array $query, $element, $index) {
    // Build the 'page' query parameter. This is built based on the current
    // page of each pager element (or NULL if the pager is not set), with the
    // exception of the requested page index for the current element.
    $element_pages = [];
    $max = $this->getMaxPagerElementId();
    for ($i = 0; $i <= $max; $i++) {
      $currentPage = ($pager = $this->getPager($i)) ? $pager->getCurrentPage() : NULL;
      $element_pages[] = ($i == $element) ? $index : $currentPage;
    }
    $query['page'] = implode(',', $element_pages);

    // Merge the query parameters passed to this function with the parameters
    // from the current request. In case of collision, the parameters passed
    // into this function take precedence.
    if ($current_query = $this->pagerParams->getQueryParameters()) {
      $query = array_merge($current_query, $query);
    }
    return $query;
  }

  /**
   * Gets the extent of the pager page element IDs.
   *
   * @return int
   *   The maximum element ID available, -1 if there are no elements.
   */
  protected function getMaxPagerElementId() {
    return empty($this->pagers) ? -1 : max(array_keys($this->pagers));
  }

  /**
   * Saves a pager to the static cache.
   *
   * @param \Drupal\Core\Pager\Pager $pager
   *   The pager.
   * @param int $element
   *   The pager index.
   */
  protected function setPager(Pager $pager, $element = 0) {
    $this->pagers[$element] = $pager;
    $this->updateGlobals();
  }

  /**
   * Updates global variables with a pager data for backwards compatibility.
   */
  protected function updateGlobals() {
    $pager_total_items = [];
    $pager_total = [];
    $pager_page_array = [];
    $pager_limits = [];

    /** @var $pager \Drupal\Core\Pager\Pager */
    foreach ($this->pagers as $pager_id => $pager) {
      $pager_total_items[$pager_id] = $pager->getTotalItems();
      $pager_total[$pager_id] = $pager->getTotalPages();
      $pager_page_array[$pager_id] = $pager->getCurrentPage();
      $pager_limits[$pager_id] = $pager->getLimit();
    }

    $GLOBALS['pager_total_items'] = new DeprecatedArray($pager_total_items, 'Global variable $pager_total_items is deprecated in drupal:8.8.0 and is removed in drupal:9.0.0. Use \Drupal\Core\Pager\PagerManagerInterface instead. See https://www.drupal.org/node/2779457');
    $GLOBALS['pager_total'] = new DeprecatedArray($pager_total, 'Global variable $pager_total is deprecated in drupal:8.8.0 and is removed in drupal:9.0.0. Use \Drupal\Core\Pager\PagerManagerInterface instead. See https://www.drupal.org/node/2779457');
    $GLOBALS['pager_page_array'] = new DeprecatedArray($pager_page_array, 'Global variable $pager_page_array is deprecated in drupal:8.8.0 and is removed in drupal:9.0.0. Use \Drupal\Core\Pager\PagerManagerInterface instead. See https://www.drupal.org/node/2779457');
    $GLOBALS['pager_limits'] = new DeprecatedArray($pager_limits, 'Global variable $pager_limits is deprecated in drupal:8.8.0 and is removed in drupal:9.0.0. Use \Drupal\Core\Pager\PagerManagerInterface instead. See https://www.drupal.org/node/2779457');
  }

}
