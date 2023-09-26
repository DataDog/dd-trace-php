/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * @api
 */
define([
    'mageUtils',
    'moment',
    './column'
], function (utils, moment, Column) {
    'use strict';

    return Column.extend({
        defaults: {
            dateFormat: 'MMM d, YYYY h:mm:ss A',
            calendarConfig: []
        },

        /**
         * Overrides base method to normalize date format.
         *
         * @returns {DateColumn} Chainable.
         */
        initConfig: function () {
            this._super();

            this.dateFormat = utils.normalizeDate(this.dateFormat ? this.dateFormat : this.options.dateFormat);

            return this;
        },

        /**
         * Formats incoming date based on the 'dateFormat' property.
         *
         * @returns {String} Formatted date.
         */
        getLabel: function (value, format) {
            var date;

            if (this.storeLocale !== undefined) {
                moment.locale(this.storeLocale, utils.extend({}, this.calendarConfig));
            }
            date = moment(this._super());

            date = date.isValid() && value[this.index] ?
                date.format(format || this.dateFormat) :
                '';

            return date;
        }
    });
});
