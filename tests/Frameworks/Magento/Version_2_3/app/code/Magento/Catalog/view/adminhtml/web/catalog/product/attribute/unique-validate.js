/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define([
    'jquery',
    'mage/backend/validation'
], function (jQuery) {
    'use strict';

    return function (config) {
        var msg = '',
            _config = jQuery.extend({
                element: null,
                message: '',
                uniqueClass: 'required-unique'
            }, config),

            /** @inheritdoc */
            messager = function () {
                return msg;
            };

        if (typeof _config.element === 'string') {
            jQuery.validator.addMethod(
                _config.element,

                function (value, element) {
                    var inputs = jQuery(element)
                            .closest('table')
                            .find('.' + _config.uniqueClass + ':visible'),
                        valuesHash = {},
                        isValid = true,
                        duplicates = [];

                    inputs.each(function (el) {
                        var inputValue = inputs[el].value;

                        if (typeof valuesHash[inputValue] !== 'undefined') {
                            isValid = false;
                            duplicates.push(inputValue);
                        }
                        valuesHash[inputValue] = el;
                    });

                    if (!isValid) {
                        msg = _config.message + ' (' + duplicates.join(', ') + ')';
                    }

                    return isValid;
                },

                messager
            );
        }
    };
});
