/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define([
    'jquery'
], function ($) {
    'use strict';

    $.widget('mage.trimInput', {
        options: {
            cache: {}
        },

        /**
         * Widget initialization
         * @private
         */
        _create: function () {
            this.options.cache.input = $(this.element);
            this._bind();
        },

        /**
         * Event binding, will monitor change, keyup and paste events.
         * @private
         */
        _bind: function () {
            if (this.options.cache.input.length) {
                this._on(this.options.cache.input, {
                    'change': this._trimInput,
                    'keyup': this._trimInput,
                    'paste': this._trimInput
                });
            }
        },

        /**
         * Trim value
         * @private
         */
        _trimInput: function () {
            // Safari caret position workaround: storing carter position
            var caretStart, caretEnd, input;

            caretStart = this.options.cache.input.get(0).selectionStart;
            caretEnd = this.options.cache.input.get(0).selectionEnd;

            input = this._getInputValue().trim();

            this.options.cache.input.val(input);

            // Safari caret position workaround: setting caret position to previously stored values
            if (caretStart !== null && caretEnd !== null) {
                this.options.cache.input.get(0).setSelectionRange(caretStart, caretEnd);
            }
        },

        /**
         * Get input value
         * @returns {*}
         * @private
         */
        _getInputValue: function () {
            return this.options.cache.input.val();
        }
    });

    return $.mage.trimInput;
});
