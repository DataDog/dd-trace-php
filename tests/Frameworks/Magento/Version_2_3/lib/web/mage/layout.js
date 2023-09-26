/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * @deprecated since version 2.2.0
 */
/* eslint-disable strict */
define(['underscore'], function (_) {
    return {
        /**
         * @param {Object} config
         */
        build: function (config) {
            var types = _.map(_.flatten(config), function (item) {
                return item.type;
            });

            require(types, function () {});
        }
    };
});
