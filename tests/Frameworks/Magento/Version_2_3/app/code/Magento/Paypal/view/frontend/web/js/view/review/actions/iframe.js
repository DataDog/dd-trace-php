/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * @deprecated since version 2.2.0
 */
define([
    'uiComponent',
    'Magento_Paypal/js/model/iframe'
], function (Component, iframe) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Magento_Paypal/review/actions/iframe'
        },

        /**
         * @return {*}
         */
        getCode: function () {
            return this.index;
        },

        /**
         * @return {String}
         */
        getActionUrl: function () {
            return this.isInAction() ? window.checkoutConfig.payment.paypalIframe.actionUrl[this.getCode()] : '';
        },

        /**
         * @return {Boolean}
         */
        afterSave: function () {
            iframe.setIsInAction(true);

            return false;
        },

        /**
         * @return {*}
         */
        isInAction: function () {
            return iframe.getIsInAction()();
        },

        /**
         * @param {Object} context
         * @return {Function}
         */
        placeOrder: function (context) {
            return context.placeOrder.bind(context, this.afterSave);
        }
    });
});
