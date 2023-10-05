<?php

namespace Drupal\layout_builder\Plugin\Layout;

use Drupal\Core\Layout\LayoutDefault;

/**
 * Provides a layout plugin that produces no output.
 *
 * @see \Drupal\layout_builder\Field\LayoutSectionItemList::removeSection()
 * @see \Drupal\layout_builder\SectionStorage\SectionStorageTrait::addBlankSection()
 * @see \Drupal\layout_builder\SectionStorage\SectionStorageTrait::hasBlankSection()
 *
 * @internal
 *   This layout plugin is intended for internal use by Layout Builder only.
 *
 * @Layout(
 *   id = "layout_builder_blank",
 * )
 */
class BlankLayout extends LayoutDefault {

  /**
   * {@inheritdoc}
   */
  public function build(array $regions) {
    // Return no output.
    return [];
  }

}
