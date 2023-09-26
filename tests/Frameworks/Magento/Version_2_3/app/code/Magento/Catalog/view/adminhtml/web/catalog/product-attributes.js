/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define([
    'jquery',
    'underscore',
    'uiRegistry',
    'jquery/ui',
    'mage/translate'
], function ($, _, registry) {
    'use strict';

    $.widget('mage.productAttributes', {
        /** @inheritdoc */
        _create: function () {
            this._on({
                'click': '_showPopup'
            });
        },

        /**
         * @private
         */
        _initModal: function () {
            var self = this;

            this.modal = $('<div id="create_new_attribute"/>').modal({
                title: $.mage.__('New Attribute'),
                type: 'slide',
                buttons: [],

                /** @inheritdoc */
                opened: function () {
                    $(this).parent().addClass('modal-content-new-attribute');
                    self.iframe = $('<iframe id="create_new_attribute_container">').attr({
                        src: self._prepareUrl(),
                        frameborder: 0
                    });
                    self.modal.append(self.iframe);
                    self._changeIframeSize();
                    $(window).off().on('resize.modal', _.debounce(self._changeIframeSize.bind(self), 400));
                },

                /** @inheritdoc */
                closed: function () {
                    var doc = self.iframe.get(0).document;

                    if (doc && $.isFunction(doc.execCommand)) {
                        //IE9 break script loading but not execution on iframe removing
                        doc.execCommand('stop');
                        self.iframe.remove();
                    }
                    self.modal.data('modal').modal.remove();
                    $(window).off('resize.modal');
                }
            });
        },

        /**
         * @return {Number}
         * @private
         */
        _getHeight: function () {
            var modal = this.modal.data('modal').modal,
                modalHead = modal.find('header'),
                modalHeadHeight = modalHead.outerHeight(),
                modalHeight = modal.outerHeight(),
                modalContentPadding = this.modal.parent().outerHeight() - this.modal.parent().height();

            return modalHeight - modalHeadHeight - modalContentPadding;
        },

        /**
         * @return {Number}
         * @private
         */
        _getWidth: function () {
            return this.modal.width();
        },

        /**
         * @private
         */
        _changeIframeSize: function () {
            this.modal.parent().outerHeight(this._getHeight());
            this.iframe.outerHeight(this._getHeight());
            this.iframe.outerWidth(this._getWidth());

        },

        /**
         * @return {String}
         * @private
         */
        _prepareUrl: function () {
            var productSource,
                attributeSetId = '';

            if (this.options.dataProvider) {
                try {
                    productSource = registry.get(this.options.dataProvider);
                    attributeSetId = productSource.data.product['attribute_set_id'];
                } catch (e) {}
            }

            return this.options.url +
                (/\?/.test(this.options.url) ? '&' : '?') +
                'set=' + attributeSetId;
        },

        /**
         * @private
         */
        _showPopup: function () {
            this._initModal();
            this.modal.modal('openModal');
        }
    });

    return $.mage.productAttributes;
});
