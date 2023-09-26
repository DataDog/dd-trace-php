/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define([
    'jquery'
], function ($) {
    'use strict';

    return function (data, element) {

        $(element).on('save', function () {
            if ($(this).valid()) {
                $('body').trigger('processStart');
            }
        });
    };
});
