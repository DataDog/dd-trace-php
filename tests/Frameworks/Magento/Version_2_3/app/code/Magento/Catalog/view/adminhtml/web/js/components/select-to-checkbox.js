/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * @deprecated since version 2.2.0
 */
define([
    'Magento_Ui/js/form/element/abstract'
], function (Abstract) {
    'use strict';

    return Abstract.extend({

        /**
         * Converts initial value to integer
         *
         * @returns {Abstract}
         */
        setInitialValue: function () {
            this._super();
            this.value(+this.value());

            return this;
        },

        /**
         * Converts new value to integer
         *
         * @returns {Boolean}
         */
        onUpdate: function () {
            this._super();
            this.value(+this.value());

            return this._super();
        }
    });
});
