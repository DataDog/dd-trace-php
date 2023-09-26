/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define([
    'jquery',
    'mage/mage',
    'mageTranslationDictionary'
], function ($, mage, dictionary) {
    'use strict';

    $.extend(true, $, {
        mage: {
            translate: (function () {
                /**
                 * Key-value translations storage
                 * @type {Object}
                 * @private
                 */
                var _data = dictionary;

                return {
                    /**
                     * Add new translation (two string parameters) or several translations (object)
                     */
                    add: function () {
                        if (arguments.length > 1) {
                            _data[arguments[0]] = arguments[1];
                        } else if (typeof arguments[0] === 'object') {
                            $.extend(_data, arguments[0]);
                        }
                    },

                    /**
                     * Make a translation with parsing (to handle case when _data represents tuple)
                     * @param {String} text
                     * @return {String}
                     */
                    translate: function (text) {
                        return _data[text] ? _data[text] : text;
                    }
                };
            }())
        }
    });
    $.mage.__ = $.proxy($.mage.translate.translate, $.mage.translate);

    return $.mage.__;
});
