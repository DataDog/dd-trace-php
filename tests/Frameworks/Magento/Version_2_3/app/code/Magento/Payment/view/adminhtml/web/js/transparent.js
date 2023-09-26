/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/* global FORM_KEY */
/* @api */
define([
    'jquery',
    'mage/template',
    'Magento_Ui/js/modal/alert',
    'Magento_Payment/js/model/credit-card-validation/validator'
], function ($, mageTemplate, alert) {
    'use strict';

    $.widget('mage.transparent', {
        options: {
            editFormSelector: '#edit_form',
            hiddenFormTmpl:
                '<form target="<%= data.target %>" action="<%= data.action %>"' +
                'method="POST" hidden' +
                'enctype="application/x-www-form-urlencoded" class="no-display">' +
                    '<% _.each(data.inputs, function(val, key){ %>' +
                    '<input value="<%= val %>" name="<%= key %>" type="hidden">' +
                    '<% }); %>' +
                '</form>',
            cgiUrl: null,
            orderSaveUrl: null,
            controller: null,
            gateway: null,
            dateDelim: null,
            cardFieldsMap: null,
            expireYearLength: 2
        },

        /**
         * @private
         */
        _create: function () {
            this.hiddenFormTmpl = mageTemplate(this.options.hiddenFormTmpl);

            $(this.options.editFormSelector).on('changePaymentMethod', this._setPlaceOrderHandler.bind(this));
            $(this.options.editFormSelector).trigger('changePaymentMethod', [
                $(this.options.editFormSelector).find(':radio[name="payment[method]"]:checked').val()
            ]);
        },

        /**
         * Handler for form submit.
         *
         * @param {Object} event
         * @param {String} method
         */
        _setPlaceOrderHandler: function (event, method) {
            if (method === this.options.gateway) {
                $(this.options.editFormSelector)
                    .off('submitOrder')
                    .on('submitOrder.' +  this.options.gateway, this._placeOrderHandler.bind(this));
            } else {
                $(this.options.editFormSelector)
                    .off('submitOrder.' + this.options.gateway);
            }
        },

        /**
         * Handler for form submit to call gateway for credit card validation.
         *
         * @return {Boolean}
         * @private
         */
        _placeOrderHandler: function () {
            if ($(this.options.editFormSelector).valid()) {
                this._orderSave();
            } else {
                $('body').trigger('processStop');
            }

            return false;
        },

        /**
         * Handler for Place Order button to call gateway for credit card validation.
         * Save order and generate post data for gateway call.
         *
         * @private
         */
        _orderSave: function () {
            var postData = {
                'form_key': FORM_KEY,
                'cc_type': this.ccType()
            };

            $.ajax({
                url: this.options.orderSaveUrl,
                type: 'post',
                context: this,
                data: postData,
                dataType: 'json',

                /**
                 * Success callback
                 * @param {Object} response
                 */
                success: function (response) {
                    if (response.success && response[this.options.gateway]) {
                        this._postPaymentToGateway(response);
                    } else {
                        this._processErrors(response);
                    }
                },

                /** @inheritdoc */
                complete: function () {
                    $('body').trigger('processStop');
                }
            });
        },

        /**
         * Post data to gateway for credit card validation.
         *
         * @param {Object} response
         * @private
         */
        _postPaymentToGateway: function (response) {
            var $iframeSelector = $('[data-container="' + this.options.gateway + '-transparent-iframe"]'),
                data,
                tmpl,
                iframe;

            data = this._preparePaymentData(response);
            tmpl = this.hiddenFormTmpl({
                data: {
                    target: $iframeSelector.attr('name'),
                    action: this.options.cgiUrl,
                    inputs: data
                }
            });

            iframe = $iframeSelector
                .on('submit', function (event) {
                    event.stopPropagation();
                });
            $(tmpl).appendTo(iframe).submit();
            iframe.html('');
        },

        /**
         * @returns {String}
         */
        ccType: function () {
            return this.element.find(
                '[data-container="' + this.options.gateway + '-cc-type"]'
            ).val();
        },

        /**
         * Add credit card fields to post data for gateway.
         *
         * @param {Object} response
         * @private
         */
        _preparePaymentData: function (response) {
            var ccfields,
                data,
                preparedata;

            data = response[this.options.gateway].fields;
            ccfields = this.options.cardFieldsMap;

            if (this.element.find('[data-container="' + this.options.gateway + '-cc-cvv"]').length) {
                data[ccfields.cccvv] = this.element.find(
                    '[data-container="' + this.options.gateway + '-cc-cvv"]'
                ).val();
            }
            preparedata = this._prepareExpDate();
            data[ccfields.ccexpdate] = preparedata.month + this.options.dateDelim + preparedata.year;
            data[ccfields.ccnum] = this.element.find(
                '[data-container="' + this.options.gateway + '-cc-number"]'
            ).val();

            return data;
        },

        /**
         * Grab Month and Year into one
         * @returns {Object}
         * @private
         */
        _prepareExpDate: function () {
            var year = this.element.find('[data-container="' + this.options.gateway + '-cc-year"]').val(),
                month = parseInt(
                    this.element.find('[data-container="' + this.options.gateway + '-cc-month"]').val(), 10
                );

            if (year.length > this.options.expireYearLength) {
                year = year.substring(year.length - this.options.expireYearLength);
            }

            if (month < 10) {
                month = '0' + month;
            }

            return {
                month: month, year: year
            };
        },

        /**
         * Processing errors
         *
         * @param {Object} response
         * @private
         */
        _processErrors: function (response) {
            var msg = response['error_messages'];

            if (typeof msg === 'object') {
                alert({
                    content: msg.join('\n')
                });
            }

            if (msg) {
                alert({
                    content: msg
                });
            }
        }
    });

    return $.mage.transparent;
});
