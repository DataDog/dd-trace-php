/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * @api
 */
define([
    './abstract'
], function (Abstract) {
    'use strict';

    return Abstract.extend({
        defaults: {
            cols: 15,
            rows: 2,
            elementTmpl: 'ui/form/element/textarea'
        }
    });
});
