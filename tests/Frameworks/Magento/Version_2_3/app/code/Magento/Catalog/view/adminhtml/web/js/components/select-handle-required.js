/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * @deprecated since version 2.2.0
 */
define([
    'Magento_Ui/js/form/element/select'
], function (Select) {
    'use strict';

    return Select.extend({

        /**
         * Disable required validation, when 'use config option' checked
         */
        handleRequired: function (newValue) {
            this.validation['required-entry'] = !newValue;
            this.required(!newValue);
            this.error(false);
        }
    });
});
