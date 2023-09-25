/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define([
    'jquery'
], function ($) {
    'use strict';

    var ESC_KEY_CODE = '27';

    $(document)
        .on('click.dropdown', function (event) {
            if (!$(event.target).is('[data-toggle=dropdown].active, ' +
                '[data-toggle=dropdown].active *, ' +
                '[data-toggle=dropdown].active + .dropdown-menu, ' +
                '[data-toggle=dropdown].active + .dropdown-menu *,' +
                '[data-toggle=dropdown].active + [data-target="dropdown"],' +
                '[data-toggle=dropdown].active + [data-target="dropdown"] *')
            ) {
                $('[data-toggle=dropdown].active').trigger('close.dropdown');
            }
        })
        .on('keyup.dropdown', function (event) {
            if (event.keyCode == ESC_KEY_CODE) { //eslint-disable-line eqeqeq
                $('[data-toggle=dropdown].active').trigger('close.dropdown');
            }
        });

    /**
     * @param {Object} options
     */
    $.fn.dropdown = function (options) {
        options = $.extend({
            parent: null,
            btnArrow: '.arrow',
            activeClass: 'active'
        }, options);

        return this.each(function () {
            var elem = $(this);

            elem.off('open.dropdown, close.dropdown, click.dropdown');
            elem.on('open.dropdown', function () {
                elem
                    .addClass(options.activeClass)
                    .parent()
                    .addClass(options.activeClass);
                elem.find(options.btnArrow).text('\u25b2'); // arrow up
            });

            elem.on('close.dropdown', function () {
                elem
                    .removeClass(options.activeClass)
                    .parent()
                    .removeClass(options.activeClass);
                elem.find(options.btnArrow).text('\u25bc'); // arrow down
            });

            elem.on('click.dropdown', function () {
                var isActive = elem.hasClass('active');

                $('[data-toggle=dropdown].active').trigger('close.dropdown');
                elem.trigger(isActive ? 'close.dropdown' : 'open.dropdown');

                return false;
            });
        });
    };

    return function (data, el) {
        $(el).dropdown(data);
    };
});
