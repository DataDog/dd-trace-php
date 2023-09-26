/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * @api
 */
define([
    'Magento_Ui/js/form/components/button',
    'uiRegistry'
], function (Button, registry) {
    'use strict';

    return Button.extend({
        defaults: {
            customInstructionField: '${ $.parentName }.custom_instructions',
            label: '',
            buttonTpl: 'Magento_Theme/form/element/button'
        },

        /**
         * @private
         * @param {String} json
         * @return {String}
         */
        _parseJson: function (json) {
            return JSON.parse(json);
        },

        /**
         * @param {String} defaultRobotsTxt
         */
        reset: function (defaultRobotsTxt) {
            var customInstructions = registry.get(this.customInstructionField);

            if (customInstructions) {
                customInstructions.set('value', this._parseJson(defaultRobotsTxt));
            }
        }
    });
});
