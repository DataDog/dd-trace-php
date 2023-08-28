/* eslint-disable import/no-extraneous-dependencies */
// cSpell:words drupalhtmlwriter
import { Plugin } from 'ckeditor5/src/core';
import DrupalHtmlWriter from './drupalhtmlwriter';

/**
 * A plugin that overrides the CKEditor HTML writer.
 *
 * Overrides the CKEditor 5 HTML writer to account for Drupal XSS filtering
 * needs.
 *
 * @see https://www.drupal.org/project/drupal/issues/3227831
 * @see DrupalHtmlBuilder._escapeAttribute
 *
 * @private
 */
class DrupalHtmlEngine extends Plugin {
  /**
   * @inheritdoc
   */
  init() {
    this.editor.data.processor.htmlWriter = new DrupalHtmlWriter();
  }

  /**
   * @inheritdoc
   */
  static get pluginName() {
    return 'DrupalHtmlEngine';
  }
}

export default DrupalHtmlEngine;
