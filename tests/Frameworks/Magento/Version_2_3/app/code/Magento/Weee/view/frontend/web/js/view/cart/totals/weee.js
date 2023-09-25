/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define([
    'Magento_Weee/js/view/checkout/summary/weee'
], function (Component) {
    'use strict';

    return Component.extend({

        /**
         * @override
         */
        isFullMode: function () {
            return true;
        }
    });
});
