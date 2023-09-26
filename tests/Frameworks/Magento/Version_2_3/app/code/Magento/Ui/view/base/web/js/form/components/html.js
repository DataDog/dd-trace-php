/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * @api
 */
define([
    'jquery',
    'underscore',
    'uiComponent'
], function ($, _, Component) {
    'use strict';

    return Component.extend({
        defaults: {
            content:        '',
            showSpinner:    false,
            loading:        false,
            visible:        true,
            template:       'ui/content/content',
            additionalClasses: {},
            ignoreTmpls: {
                content: true
            }
        },

        /**
         * Extends instance with default config, calls 'initialize' method of
         *     parent, calls 'initAjaxConfig'
         */
        initialize: function () {
            _.bindAll(this, 'onContainerToggle', 'onDataLoaded');

            this._super()
                ._setClasses()
                .initAjaxConfig();

            return this;
        },

        /**
         * Calls 'initObservable' method of parent, initializes observable
         * properties of instance
         *
         * @return {Object} - reference to instance
         */
        initObservable: function () {
            this._super()
                .observe('content loading visible');

            return this;
        },

        /**
         * Extends 'additionalClasses' object.
         *
         * @returns {Group} Chainable.
         */
        _setClasses: function () {
            var additional = this.additionalClasses,
                classes;

            if (_.isString(additional)) {
                additional = this.additionalClasses.split(' ');
                classes = this.additionalClasses = {};

                additional.forEach(function (name) {
                    classes[name] = true;
                }, this);
            }

            _.extend(this.additionalClasses, {
                'admin__scope-old': !!additional
            });

            return this;
        },

        /** @inheritdoc */
        initContainer: function (parent) {
            this._super();

            parent.on('active', this.onContainerToggle);

            return this;
        },

        /**
         * Initializes default ajax config on instance
         *
         * @return {Object} - reference to instance
         */
        initAjaxConfig: function () {
            this.ajaxConfig = {
                url: this.url,
                data: {
                    FORM_KEY: window.FORM_KEY
                },
                success:    this.onDataLoaded
            };

            return this;
        },

        /**
         * Calls 'loadData' if both 'active' variable and 'shouldLoad'
         * property are truthy
         *
         * @param  {Boolean} active
         */
        onContainerToggle: function (active) {
            if (active && this.shouldLoad()) {
                this.loadData();
            }
        },

        /**
         * Defines if instance has 'content' property defined.
         *
         * @return {Boolean} [description]
         */
        hasData: function () {
            return !!this.content();
        },

        /**
         * Defines if instance should load external data
         *
         * @return {Boolean}
         */
        shouldLoad: function () {
            return this.url && !this.hasData() && !this.loading();
        },

        /**
         * Sets loading property to true, makes ajax call
         *
         * @return {Object} - reference to instance
         */
        loadData: function () {
            this.loading(true);

            $.ajax(this.ajaxConfig);

            return this;
        },

        /**
         * Ajax's request success handler. Calls 'updateContent' passing 'data'
         * to it, then sets 'loading' property to false.
         *
         * @param  {String} data
         */
        onDataLoaded: function (data) {
            this.updateContent(data)
                .loading(false);
        },

        /**
         * Sets incoming data 'content' property's value
         *
         * @param  {String} content
         * @return {Object} - reference to instance
         */
        updateContent: function (content) {
            this.content(content);

            return this;
        }
    });
});
