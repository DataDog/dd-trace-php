/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
define([
    'jquery',
    'uiClass',
    'Magento_Paypal/js/rule',
    'mageUtils',
    'underscore'
], function ($, Class, Rule, utils, _) {
    'use strict';

    return Class.extend({
        defaults: {

            /**
             * The event corresponding to the state change
             */
            systemEvent: 'change',

            /**
             * The rules applied after the page is loaded
             */
            afterLoadRules: [],

            /**
             * An attribute of the element responsible for the activation of the payment method (data attribute)
             */
            enableButton:   '[data-enable="payment"]',

            /**
             * An attribute of the element responsible for the activation of the Payflow Express (data attribute)
             */
            enableExpress:  '[data-enable="express"]',

            /**
             * An attribute of the element responsible for the activation of the
             * PayPal Express In-Context Checkout Experience (data attribute)
             */
            enableInContextPayPal: '[data-enable="in-context-api"]',

            /**
             * An attribute of the element responsible for the activation of the Payflow Bml (data attribute)
             */
            enableBml:      '[data-enable="bml"]',

            /**
             * An attribute of the element responsible for the activation of the PayPal Bml (data attribute)
             */
            enableBmlPayPal:      '[data-enable="bml-api"]',

            /**
             * An attribute of the element responsible for the visibility of the PayPal Merchant Id (data attribute)
             */
            dependsMerchantId:  '[data-enable="merchant-id"]',

            /**
             * An attribute of the element responsible for the visibility of the Payflow Bml Sort Order (data attribute)
             */
            dependsBmlSortOrder:    '[data-enable="bml-sort-order"]',

            /**
             * An attribute of the element responsible for the visibility of the PayPal Bml Sort Order (data attribute)
             */
            dependsBmlApiSortOrder:    '[data-enable="bml-api-sort-order"]',

            /**
             * An attribute of the element responsible for the visibility of the
             * button Label credit option (data attribute)
             */
            dependsButtonLabel: '[data-enable="button-label"]',

            /**
             * An attribute of the element responsible for the visibility of the
             * button Label credit option on load (data attribute)
             */
            dependsDisableFundingOptions: '[data-enable="disable-funding-options"]',

            /**
             * Templates element selectors
             */
            templates: {
                elementSelector: 'div.section-config tr[id$="${ $.identifier }"]:first'
            }
        },

        /**
         * Constructor
         *
         * @param {Object} config
         * @param {String} identifier
         * @returns {exports.initialize}
         */
        initialize: function (config, identifier) {
            this.initConfig(config);
            this.$self = this.createElement(identifier);

            return this;
        },

        /**
         * Initialization events
         *
         * @returns {exports.initEvents}
         */
        initEvents: function () {
            _.each(this.config.events, function (elementEvents, selector) {

                var solution = this,
                    selectorButton = solution.$self.find(selector),
                    $self = solution.$self,
                    events = elementEvents;

                selectorButton.on(solution.systemEvent, function () {
                    _.each(events, function (elementEvent, name) {

                        var predicate = elementEvent.predicate,
                            result = true,

                            /**
                             * @param {Function} functionPredicate
                             */
                            predicateCallback = function (functionPredicate) {
                                result = functionPredicate(solution, predicate.message, predicate.argument);

                                if (result) {
                                    $self.trigger(name);
                                } else {
                                    $self.trigger(predicate.event);
                                }
                            };

                        if (solution.getValue($(this)) === elementEvent.value ||
                            $(this).prop('multiple') && solution.checkMultiselectValue($(this), elementEvent)
                        ) {
                            if (predicate.name) {
                                require([
                                    'Magento_Paypal/js/predicate/' + predicate.name
                                ], predicateCallback);
                            } else {
                                $self.trigger(name);
                            }
                        }
                    }, this);
                });
            }, this);

            return this;
        },

        /**
         * @param {Object} $element
         * @returns {*}
         */
        getValue: function ($element) {
            if ($element.is(':checkbox')) {
                return $element.prop('checked') ? '1' : '0';
            }

            return $element.val();
        },

        /**
         * Check multiselect value based on include value
         *
         * @param {Object} $element
         * @param {Object} elementEvent
         * @returns {Boolean}
         */
        checkMultiselectValue: function ($element, elementEvent) {
            var isValueSelected = $.inArray(elementEvent.value, $element.val()) >= 0;

            if (elementEvent.include) {
                isValueSelected = (isValueSelected ? 'true' : 'false') === elementEvent.include;
            }

            return isValueSelected;
        },

        /**
         * Adding event listeners
         *
         * @returns {exports.addListeners}
         */
        addListeners: function () {

            _.each(this.config.relations, function (rules, targetName) {

                var $target = this.createElement(targetName);

                _.each(rules, function (instances, instanceName) {

                    _.each(instances, function (instance) {
                        var handler = new Rule({
                            name: instanceName,
                            $target: $target,
                            $owner: this.$self,
                            data: {
                                buttonConfiguration: this.buttonConfiguration,
                                enableButton: this.enableButton,
                                enableExpress: this.enableExpress,
                                enableInContextPayPal: this.enableInContextPayPal,
                                enableBml: this.enableBml,
                                enableBmlPayPal: this.enableBmlPayPal,
                                dependsMerchantId: this.dependsMerchantId,
                                dependsBmlSortOrder: this.dependsBmlSortOrder,
                                dependsBmlApiSortOrder: this.dependsBmlApiSortOrder,
                                dependsButtonLabel: this.dependsButtonLabel,
                                dependsDisableFundingOptions: this.dependsDisableFundingOptions,
                                solutionsElements: this.solutionsElements,
                                argument: instance.argument
                            }
                        });

                        if (instance.event === ':load') {
                            this.afterLoadRules.push(handler);

                            return;
                        }

                        this.$self.on(instance.event, _.bind(handler.apply, handler));
                    }, this);
                }, this);
            }, this);

            return this;
        },

        /**
         * Create a jQuery element according to selector
         *
         * @param {String} identifier
         * @returns {*}
         */
        createElement: function (identifier) {
            if (identifier === ':self') {
                return this.$self;
            }

            return $(utils.template(this.templates.elementSelector, {
                'identifier': identifier
            }));
        },

        /**
         * Assign solutions elements
         *
         * @param {Object} elements
         * @returns {exports.setSolutionsElements}
         */
        setSolutionsElements: function (elements) {
            this.solutionsElements = elements;

            return this;
        }
    });
});
