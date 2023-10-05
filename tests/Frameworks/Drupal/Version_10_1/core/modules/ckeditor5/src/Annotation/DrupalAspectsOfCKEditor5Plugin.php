<?php

declare(strict_types = 1);

namespace Drupal\ckeditor5\Annotation;

use Drupal\ckeditor5\Plugin\CKEditor5PluginDefault;
use Drupal\Component\Annotation\Plugin;

/**
 * Defines the "Drupal aspects of a CKEditor5Plugin" annotation object.
 *
 * Plugin Namespace: Plugin\CKEditor5Plugin.
 *
 * @see \Drupal\ckeditor5\Plugin\CKEditorPluginInterface
 * @see \Drupal\ckeditor5\Plugin\CKEditorPluginBase
 * @see \Drupal\ckeditor5\Plugin\CKEditorPluginManager
 * @see plugin_api
 *
 * @Annotation
 * @see \Drupal\ckeditor5\Annotation\CKEditor5Plugin
 * @see \Drupal\ckeditor5\Annotation\CKEditor5AspectsOfCKEditor5Plugin
 */
class DrupalAspectsOfCKEditor5Plugin extends Plugin {

  /**
   * The human-readable name of the CKEditor plugin.
   *
   * @var \Drupal\Core\Annotation\Translation
   * @ingroup plugin_translatable
   */
  public $label;

  /**
   * The CKEditor 5 plugin class.
   *
   * If not specified, the CKEditor5PluginDefault class is used.
   *
   * This property is optional and it does not need to be declared.
   *
   * @var string
   */
  public $class = CKEditor5PluginDefault::class;

  /**
   * The CKEditor 5 plugin deriver class.
   *
   * This property is optional and it does not need to be declared.
   *
   * @var string|null
   */
  public $deriver = NULL;

  /**
   * The library this plugin requires.
   *
   * This property is optional and it does not need to be declared.
   *
   * @var string|false
   */
  public $library = FALSE;

  /**
   * The admin library this plugin provides.
   *
   * This property is optional and it does not need to be declared.
   *
   * @var string|false
   */
  public $admin_library = FALSE;

  /**
   * List of elements and attributes provided.
   *
   * An array of strings, or false if no elements are provided.
   *
   * Syntax for each array value:
   * - <element> only allows that HTML element with no attributes
   * - <element attrA attrB> only allows that HTML element with attributes attrA
   *   and attrB, and any value for those attributes.
   * - <element attrA="foo bar baz" attrB="qux-*"> only allows that HTML element
   *   with attributes attrA (if attrA contains one of the three listed values)
   *   and attrB (if its value has the provided prefix).
   * - <element data-*> only allows that HTML element with any attribute that
   *   has the given prefix.
   *
   * Note that <element> means such an element (tag) can be created, whereas
   * <element attrA attrB> means that `attrA` and `attrB` can be created on the
   * tag. If a plugin supports both creating the element as well as setting some
   * attributes or attribute values on it, it should have distinct entries in
   * the list.
   * For example, for a link plugin: `<a>` and `<a href>`. The first indicates
   * the plugin can create such tags, the second indicates it can set the `href`
   * attribute on it. If the first were omitted, the Drupal CKEditor 5 module
   * would interpret that as "this plugin cannot create `<a>`, it can only set
   * the `href` attribute on it".
   *
   * @var string[]|false
   *
   * @see \Drupal\ckeditor5\Plugin\CKEditor5PluginDefinition::getCreatableElements()
   */
  public $elements;

  /**
   * List of toolbar items the plugin provides.
   *
   * This property is optional and it does not need to be declared.
   *
   * @var array[]
   */
  public $toolbar_items = [];

  /**
   * List of conditions to enable this plugin.
   *
   * This property is optional and it does not need to be declared.
   *
   * @var array|false
   */
  public $conditions = FALSE;

}
