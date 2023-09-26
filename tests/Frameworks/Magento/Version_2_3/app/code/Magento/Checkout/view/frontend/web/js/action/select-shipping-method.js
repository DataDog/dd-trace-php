/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * @api
 */
define([
    '../model/quote'
], function (quote) {
    'use strict';

    return function (shippingMethod) {
        quote.shippingMethod(shippingMethod);
    };
});
