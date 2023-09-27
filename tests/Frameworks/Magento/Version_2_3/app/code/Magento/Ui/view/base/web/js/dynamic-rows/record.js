/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * @api
 */
define([
    'underscore',
    'uiCollection',
    'uiRegistry'
], function (_, uiCollection, registry) {
    'use strict';

    return uiCollection.extend({
        defaults: {
            visible: true,
            disabled: true,
            headerLabel: '',
            label: '',
            positionProvider: 'position',
            imports: {
                data: '${ $.provider }:${ $.dataScope }'
            },
            listens: {
                position: 'initPosition',
                elems: 'setColumnVisibleListener'
            },
            links: {
                position: '${ $.name }.${ $.positionProvider }:value'
            },
            exports: {
                recordId: '${ $.provider }:${ $.dataScope }.record_id'
            },
            modules: {
                parentComponent: '${ $.parentName }'
            }
        },

        /**
         * Extends instance with default config, calls initialize of parent
         * class, calls initChildren method, set observe variable.
         * Use parent "track" method - wrapper observe array
         *
         * @returns {Object} Chainable.
         */
        initialize: function () {
            var self = this;

            this._super();

            registry.async(this.name + '.' + this.positionProvider)(function (component) {

                /**
                 * Overwrite hasChanged method
                 *
                 * @returns {Boolean}
                 */
                component.hasChanged = function () {

                    /* eslint-disable eqeqeq */
                    return this.value().toString() != this.initialValue.toString();

                    /* eslint-enable eqeqeq */
                };

                if (!component.initialValue) {
                    component.initialValue = self.parentComponent().maxPosition;
                    component.bubble('update', component.hasChanged());
                }
            });

            return this;
        },

        /**
         * Init config
         *
         * @returns {Object} Chainable.
         */
        initConfig: function () {
            this._super();

            this.label = this.label || this.headerLabel;

            return this;
        },

        /**
         * Calls 'initObservable' of parent
         *
         * @returns {Object} Chainable.
         */
        initObservable: function () {
            this._super()
                .track('position')
                .observe([
                    'visible',
                    'disabled',
                    'data',
                    'label'
                ]);

            return this;
        },

        /**
         * Init element position
         *
         * @param {Number} position - element position
         */
        initPosition: function (position) {
            var pos = parseInt(position, 10);

            this.parentComponent().setMaxPosition(pos, this);

            if (!pos && pos !== 0) {
                this.position = this.parentComponent().maxPosition;
            }
        },

        /**
         * Set column visibility listener
         */
        setColumnVisibleListener: function () {
            var elem = _.find(this.elems(), function (curElem) {
                return !curElem.hasOwnProperty('visibleListener');
            });

            if (!elem) {
                return;
            }

            this.childVisibleListener(elem);

            if (!elem.visibleListener) {
                elem.on('visible', this.childVisibleListener.bind(this, elem));
            }

            elem.visibleListener = true;
        },

        /**
         * Child visibility listener
         *
         * @param {Object} data
         */
        childVisibleListener: function (data) {
            this.setVisibilityColumn(data.index, data.visible());
        },

        /**
         * Reset data to initial value.
         * Call method reset on child elements.
         */
        reset: function () {
            var elems = this.elems(),
                nameIsEqual,
                dataScopeIsEqual;

            _.each(elems, function (elem) {
                nameIsEqual = this.name + '.' + this.positionProvider === elem.name;
                dataScopeIsEqual = this.dataScope === elem.dataScope;

                if (!(nameIsEqual || dataScopeIsEqual) && _.isFunction(elem.reset)) {
                    elem.reset();
                }
            }, this);

            return this;
        },

        /**
         * Clear data
         *
         * @returns {Collection} Chainable.
         */
        clear: function () {
            var elems = this.elems(),
                nameIsEqual,
                dataScopeIsEqual;

            _.each(elems, function (elem) {
                nameIsEqual = this.name + '.' + this.positionProvider === elem.name;
                dataScopeIsEqual = this.dataScope === elem.dataScope;

                if (!(nameIsEqual || dataScopeIsEqual) && _.isFunction(elem.reset)) {
                    elem.clear();
                }
            }, this);

            return this;
        },

        /**
         * Get label for collapsible header
         *
         * @param {String} label
         *
         * @returns {String}
         */
        getLabel: function (label) {
            if (_.isString(label)) {
                this.label(label);
            } else if (label && this.label()) {
                return this.label();
            } else {
                this.label(this.headerLabel);
            }

            return this.label();
        },

        /**
         * Set visibility to record child
         *
         * @param {Boolean} state
         */
        setVisible: function (state) {
            this.elems.each(function (cell) {
                cell.visible(state);
            });
        },

        /**
         * Set visibility to child by index
         *
         * @param {Number} index
         * @param {Boolean} state
         */
        setVisibilityColumn: function (index, state) {
            var elems = this.elems(),
                curElem = parseInt(index, 10),
                label;

            if (!this.parentComponent()) {
                return false;
            }

            if (_.isNaN(curElem)) {
                _.findWhere(elems, {
                    index: index
                }).visible(state);
                label = _.findWhere(this.parentComponent().labels(), {
                    name: index
                });
                label.defaultLabelVisible && label.visible(state);
            } else {
                elems[curElem].visible(state);
            }
        },

        /**
         * Set disabled to child
         *
         * @param {Boolean} state
         */
        setDisabled: function (state) {
            this.elems.each(function (cell) {
                cell.disabled(state);
            });
        },

        /**
         * Set disabled to child by index
         *
         * @param {Number} index
         * @param {Boolean} state
         */
        setDisabledColumn: function (index, state) {
            index = ~~index;
            this.elems()[index].disabled(state);
        }
    });
});
