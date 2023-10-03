<?php

namespace Drupal\menu_link_content\Plugin\migrate\source;

use Drupal\Component\Utility\Unicode;
use Drupal\migrate_drupal\Plugin\migrate\source\DrupalSqlBase;
use Drupal\migrate\Row;

/**
 * Drupal 6/7 menu link source from database.
 *
 * Available configuration keys:
 * - menu_name: (optional) The menu name(s) to filter menu links from the source
 *   can be a string or an array. If not declared then menu links of all menus
 *   are retrieved.
 *
 * Examples:
 *
 * @code
 * source:
 *   plugin: menu_link
 *   menu_name: main-menu
 * @endcode
 *
 * In this example menu links of main-menu are retrieved from the source
 * database.
 *
 * @code
 * source:
 *   plugin: menu_link
 *   menu_name: [main-menu, navigation]
 * @endcode
 *
 * In this example menu links of main-menu and navigation menus are retrieved
 * from the source database.
 *
 * For additional configuration keys, refer to the parent classes:
 * @see \Drupal\migrate\Plugin\migrate\source\SqlBase
 * @see \Drupal\migrate\Plugin\migrate\source\SourcePluginBase
 *
 * @MigrateSource(
 *   id = "menu_link",
 *   source_module = "menu"
 * )
 */
class MenuLink extends DrupalSqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('menu_links', 'ml')
      ->fields('ml')
      // Shortcut set links are migrated by the d7_shortcut migration.
      // Shortcuts are not used in Drupal 6.
      // @see Drupal\shortcut\Plugin\migrate\source\d7\Shortcut::query()
      ->condition('ml.menu_name', 'shortcut-set-%', 'NOT LIKE');
    $and = $query->andConditionGroup()
      ->condition('ml.module', 'menu')
      ->condition('ml.router_path', ['admin/build/menu-customize/%', 'admin/structure/menu/manage/%'], 'NOT IN');
    $condition = $query->orConditionGroup()
      ->condition('ml.customized', 1)
      ->condition($and);
    $query->condition($condition);
    if (isset($this->configuration['menu_name'])) {
      $query->condition('ml.menu_name', (array) $this->configuration['menu_name'], 'IN');
    }
    $query->leftJoin('menu_links', 'pl', '[ml].[plid] = [pl].[mlid]');
    $query->addField('pl', 'link_path', 'parent_link_path');
    $query->orderBy('ml.depth');
    $query->orderby('ml.mlid');
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'menu_name' => $this->t("The menu name. All links with the same menu name (such as 'navigation') are part of the same menu."),
      'mlid' => $this->t('The menu link ID (mlid) is the integer primary key.'),
      'plid' => $this->t('The parent link ID (plid) is the mlid of the link above in the hierarchy, or zero if the link is at the top level in its menu.'),
      'link_path' => $this->t('The Drupal path or external path this link points to.'),
      'router_path' => $this->t('For links corresponding to a Drupal path (external = 0), this connects the link to a {menu_router}.path for joins.'),
      'link_title' => $this->t('The text displayed for the link, which may be modified by a title callback stored in {menu_router}.'),
      'options' => $this->t('A serialized array of options to set on the URL, such as a query string or HTML attributes.'),
      'module' => $this->t('The name of the module that generated this link.'),
      'hidden' => $this->t('A flag for whether the link should be rendered in menus. (1 = a disabled menu link that may be shown on admin screens, -1 = a menu callback, 0 = a normal, visible link)'),
      'external' => $this->t('A flag to indicate if the link points to a full URL starting with a protocol, like http:// (1 = external, 0 = internal).'),
      'has_children' => $this->t('Flag indicating whether any links have this link as a parent (1 = children exist, 0 = no children).'),
      'expanded' => $this->t('Flag for whether this link should be rendered as expanded in menus - expanded links always have their child links displayed, instead of only when the link is in the active trail (1 = expanded, 0 = not expanded)'),
      'weight' => $this->t('Link weight among links in the same menu at the same depth.'),
      'depth' => $this->t('The depth relative to the top level. A link with plid == 0 will have depth == 1.'),
      'customized' => $this->t('A flag to indicate that the user has manually created or edited the link (1 = customized, 0 = not customized).'),
      'p1' => $this->t('The first mlid in the materialized path. If N = depth, then pN must equal the mlid. If depth > 1 then p(N-1) must equal the plid. All pX where X > depth must equal zero. The columns p1 .. p9 are also called the parents.'),
      'p2' => $this->t('The second mlid in the materialized path. See p1.'),
      'p3' => $this->t('The third mlid in the materialized path. See p1.'),
      'p4' => $this->t('The fourth mlid in the materialized path. See p1.'),
      'p5' => $this->t('The fifth mlid in the materialized path. See p1.'),
      'p6' => $this->t('The sixth mlid in the materialized path. See p1.'),
      'p7' => $this->t('The seventh mlid in the materialized path. See p1.'),
      'p8' => $this->t('The eighth mlid in the materialized path. See p1.'),
      'p9' => $this->t('The ninth mlid in the materialized path. See p1.'),
      'updated' => $this->t('Flag that indicates that this link was generated during the update from Drupal 5.'),
    ];
    $schema = $this->getDatabase()->schema();
    if ($schema->fieldExists('menu_links', 'language')) {
      $fields['language'] = $this->t("Menu link language code.");
    }
    if ($schema->fieldExists('menu_links', 'i18n_tsid')) {
      $fields['i18n_tsid'] = $this->t("Translation set id.");
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    // In Drupal 7 a language neutral menu_link can be translated. The menu
    // link is treated as if it is in the site default language. So, here
    // we look to see if this menu link has a translation and if so, the
    // language is changed to the default language. With the language set
    // the entity API will allow the saving of the translations.
    if ($row->hasSourceProperty('language') &&
      $row->getSourceProperty('language') == 'und' &&
      $this->hasTranslation($row->getSourceProperty('mlid'))) {

      $default_language = $this->variableGet('language_default', (object) ['language' => 'und']);
      $default_language = $default_language->language;
      $row->setSourceProperty('language', $default_language);
    }
    // If this menu link is part of translation set skip the translations. The
    // translations are migrated in d7_menu_link_localized.yml.
    $row->setSourceProperty('skip_translation', TRUE);
    if ($row->hasSourceProperty('i18n_tsid') && $row->getSourceProperty('i18n_tsid') != 0) {
      $source_mlid = $this->select('menu_links', 'ml')
        ->fields('ml', ['mlid'])
        ->condition('i18n_tsid', $row->getSourceProperty('i18n_tsid'))
        ->orderBy('mlid')
        ->range(0, 1)
        ->execute()
        ->fetchField();
      if ($source_mlid !== $row->getSourceProperty('mlid')) {
        $row->setSourceProperty('skip_translation', FALSE);
      }
    }
    // In Drupal 6 the language for the menu is in the options array. Set
    // property 'is_localized' so that the process pipeline can determine if
    // the menu link is localize or not.
    $row->setSourceProperty('is_localized', NULL);
    $default_language = $this->variableGet('language_default', (object) ['language' => 'und']);
    $default_language = $default_language->language;
    $options = unserialize($row->getSourceProperty('options'));
    if (isset($options['langcode'])) {
      if ($options['langcode'] != $default_language) {
        $row->setSourceProperty('language', $options['langcode']);
        $row->setSourceProperty('is_localized', 'localized');
      }
    }

    $row->setSourceProperty('options', unserialize($row->getSourceProperty('options')));
    $row->setSourceProperty('enabled', !$row->getSourceProperty('hidden'));
    $description = $row->getSourceProperty('options/attributes/title');
    if ($description !== NULL) {
      $row->setSourceProperty('description', Unicode::truncate($description, 255));
    }

    return parent::prepareRow($row);
  }

  /**
   * Determines if this  menu_link has an i18n translation.
   *
   * @param string $mlid
   *   The menu id.
   *
   * @return bool
   *   True if the menu_link has an i18n translation.
   */
  public function hasTranslation($mlid) {
    if ($this->getDatabase()->schema()->tableExists('i18n_string')) {
      $results = $this->select('i18n_string', 'i18n')
        ->fields('i18n')
        ->condition('textgroup', 'menu')
        ->condition('type', 'item')
        ->condition('objectid', $mlid)
        ->execute()
        ->fetchAll();
      if ($results) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['mlid']['type'] = 'integer';
    $ids['mlid']['alias'] = 'ml';
    return $ids;
  }

}
