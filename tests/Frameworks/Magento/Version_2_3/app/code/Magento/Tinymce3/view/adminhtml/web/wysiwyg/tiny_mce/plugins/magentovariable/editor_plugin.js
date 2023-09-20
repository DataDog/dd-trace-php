/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * @deprecated use lib/web/mage/adminhtml/wysiwyg/tiny_mce/plugins/magentovariable/editor_plugin.js instead
 */
/* global tinyMCE, tinymce, MagentovariablePlugin */
/* eslint-disable strict */
tinyMCE.addI18n({
    en: {
        magentovariable: {
            'insert_variable': 'Insert Variable'
        }
    }
});

(function () {
    tinymce.create('tinymce.plugins.MagentovariablePlugin', {
        /**
         * @param {tinymce.Editor} ed - Editor instance that the plugin is initialized in.
         * @param {String} url - Absolute URL to where the plugin is located.
         */
        init: function (ed, url) {
            ed.addCommand('mceMagentovariable', function () {
                var pluginSettings = ed.settings.magentoPluginsOptions.get('magentovariable');

                MagentovariablePlugin.setEditor(ed);
                MagentovariablePlugin.loadChooser(pluginSettings.url, ed.settings.elements);
            });

            // Register Widget plugin button
            ed.addButton('magentovariable', {
                title: 'magentovariable.insert_variable',
                cmd: 'mceMagentovariable',
                image: url + '/img/icon.gif'
            });
        },

        /**
         * @return {Object}
         */
        getInfo: function () {
            return {
                longname: 'Magento Variable Manager Plugin for TinyMCE 3.x',
                author: 'Magento Core Team',
                authorurl: 'http://magentocommerce.com',
                infourl: 'http://magentocommerce.com',
                version: '1.0'
            };
        }
    });

    // Register plugin
    tinymce.PluginManager.add('magentovariable', tinymce.plugins.MagentovariablePlugin);
})();
