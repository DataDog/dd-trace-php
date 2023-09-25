/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
define([
    'jquery',
    'mage/mage',
    'Magento_Catalog/product/view/validation',
    'catalogAddToCart'
], function ($) {
    'use strict';

    $.widget('mage.productValidate', {
        options: {
            bindSubmit: false,
            radioCheckboxClosest: '.nested',
            addToCartButtonSelector: '.action.tocart'
        },

        /**
         * Uses Magento's validation widget for the form object.
         * @private
         */
        _create: function () {
            var bindSubmit = this.options.bindSubmit;

            this.element.validation({
                radioCheckboxClosest: this.options.radioCheckboxClosest,

                /**
                 * Uses catalogAddToCart widget as submit handler.
                 * @param {Object} form
                 * @returns {Boolean}
                 */
                submitHandler: function (form) {
                    var jqForm = $(form).catalogAddToCart({
                        bindSubmit: bindSubmit
                    });

                    jqForm.catalogAddToCart('submitForm', jqForm);

                    return false;
                }
            });
            $(this.options.addToCartButtonSelector).attr('disabled', false);
        }
    });

    return $.mage.productValidate;
});
