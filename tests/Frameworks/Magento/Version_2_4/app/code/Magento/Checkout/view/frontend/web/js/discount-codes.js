/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define([
    'jquery',
    'jquery-ui-modules/widget'
], function ($) {
    'use strict';

    $.widget('mage.discountCode', {
        options: {
        },

        /** @inheritdoc */
        _create: function () {
            this.couponCode = $(this.options.couponCodeSelector);
            this.removeCoupon = $(this.options.removeCouponSelector);

            $(this.options.applyButton).on('click', $.proxy(function () {
                this.couponCode.attr('data-validate', '{required:true}');
                this.removeCoupon.attr('value', '0');
                $(this.element).validation().trigger('submit');
            }, this));

            $(this.options.cancelButton).on('click', $.proxy(function () {
                this.couponCode.removeAttr('data-validate');
                this.removeCoupon.attr('value', '1');
                this.element.trigger('submit');
            }, this));
        }
    });

    return $.mage.discountCode;
});
