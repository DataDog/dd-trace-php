/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/* @api */
define([
    'jquery',
    'mage/template',
    'Magento_Ui/js/modal/alert',
    'jquery-ui-modules/widget',
    'Magento_Payment/js/model/credit-card-validation/validator',
    'Magento_Checkout/js/model/full-screen-loader'
], function ($, mageTemplate, alert, ui, validator, fullScreenLoader) {
    'use strict';

    $.widget('mage.transparent', {
        options: {
            context: null,
            placeOrderSelector: '[data-role="review-save"]',
            paymentFormSelector: '#co-payment-form',
            updateSelectorPrefix: '#checkout-',
            updateSelectorSuffix: '-load',
            hiddenFormTmpl:
                '<form target="<%= data.target %>" action="<%= data.action %>" method="POST" ' +
                'hidden enctype="application/x-www-form-urlencoded" class="no-display">' +
                    '<% _.each(data.inputs, function(val, key){ %>' +
                    '<input value="<%= val %>" name="<%= key %>" type="hidden">' +
                    '<% }); %>' +
                '</form>',
            reviewAgreementForm: '#checkout-agreements',
            cgiUrl: null,
            orderSaveUrl: null,
            controller: null,
            gateway: null,
            dateDelim: null,
            cardFieldsMap: null,
            expireYearLength: 2
        },

        /**
         * {Function}
         * @private
         */
        _create: function () {
            this.hiddenFormTmpl = mageTemplate(this.options.hiddenFormTmpl);

            if (this.options.context) {
                this.options.context.setPlaceOrderHandler($.proxy(this._orderSave, this));
                this.options.context.setValidateHandler($.proxy(this._validateHandler, this));
            } else {
                $(this.options.placeOrderSelector)
                    .off('click')
                    .on('click', $.proxy(this._placeOrderHandler, this));
            }

            this.element.validation();
            $('[data-container="' + this.options.gateway + '-cc-number"]').on('focusout', function () {
                $(this).valid();
            });
        },

        /**
         * handler for credit card validation
         * @return {Boolean}
         * @private
         */
        _validateHandler: function () {
            return this.element.validation && this.element.validation('isValid');
        },

        /**
         * handler for Place Order button to call gateway for credit card validation
         * @return {Boolean}
         * @private
         */
        _placeOrderHandler: function () {
            if (this._validateHandler()) {
                this._orderSave();
            }

            return false;
        },

        /**
         * Save order and generate post data for gateway call
         * @private
         */
        _orderSave: function () {
            var postData = $(this.options.paymentFormSelector).serialize();

            if ($(this.options.reviewAgreementForm).length) {
                postData += '&' + $(this.options.reviewAgreementForm).serialize();
            }
            postData += '&controller=' + this.options.controller;
            postData += '&cc_type=' + this.element.find(
                '[data-container="' + this.options.gateway + '-cc-type"]'
            ).val();

            return $.ajax({
                url: this.options.orderSaveUrl,
                type: 'post',
                context: this,
                data: postData,
                dataType: 'json',

                /**
                 * {Function}
                 */
                beforeSend: function () {
                    fullScreenLoader.startLoader();
                },

                /**
                 * {Function}
                 */
                success: function (response) {
                    var preparedData,
                        msg,

                        /**
                         * {Function}
                         */
                        alertActionHandler = function () {
                            // default action
                        };

                    if (response.success && response[this.options.gateway]) {
                        preparedData = this._preparePaymentData(
                            response[this.options.gateway].fields,
                            this.options.cardFieldsMap
                        );
                        this._postPaymentToGateway(preparedData);
                    } else {
                        fullScreenLoader.stopLoader(true);

                        msg = response['error_messages'];

                        if (this.options.context) {
                            this.options.context.clearTimeout().fail();
                            alertActionHandler = this.options.context.alertActionHandler;
                        }

                        if (typeof msg === 'object') {
                            msg = msg.join('\n');
                        }

                        if (msg) {
                            alert(
                                {
                                    content: msg,
                                    actions: {

                                        /**
                                         * {Function}
                                         */
                                        always: alertActionHandler
                                    }
                                }
                            );
                        }
                    }
                }.bind(this)
            });
        },

        /**
         * Post data to gateway for credit card validation
         * @param {Object} data
         * @private
         */
        _postPaymentToGateway: function (data) {
            var tmpl,
                iframeSelector = '[data-container="' + this.options.gateway + '-transparent-iframe"]';

            tmpl = this.hiddenFormTmpl({
                data: {
                    target: $(iframeSelector).attr('name'),
                    action: this.options.cgiUrl,
                    inputs: data
                }
            });
            $(tmpl).appendTo($(iframeSelector)).trigger('submit');
        },

        /**
         * Add credit card fields to post data for gateway
         * @param {Object} data
         * @param {Object} ccfields
         * @private
         */
        _preparePaymentData: function (data, ccfields) {
            var preparedata;

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
                    this.element.find('[data-container="' + this.options.gateway + '-cc-month"]').val(),
                    10
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
        }
    });

    return $.mage.transparent;
});
