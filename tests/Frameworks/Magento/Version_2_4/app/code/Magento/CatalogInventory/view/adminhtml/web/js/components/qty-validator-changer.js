/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define([
    'Magento_Ui/js/form/element/abstract'
], function (Abstract) {
    'use strict';

    return Abstract.extend({
        defaults: {
            valueUpdate: 'input'
        },

        /**
         * Change validator
         */
        handleChanges: function (value) {
            var isDigits = value !== 1;

            this.validation['validate-integer'] = isDigits;
            this.validation['less-than-equals-to'] = isDigits ? 99999999 : 99999999.9999;
            this.validate();
        }
    });
});
