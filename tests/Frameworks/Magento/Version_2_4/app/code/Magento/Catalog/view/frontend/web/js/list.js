/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define([
    'jquery',
    'jquery-ui-modules/widget'
], function ($) {
    'use strict';

    $.widget('mage.compareList', {

        /** @inheritdoc */
        _create: function () {
            var elem = this.element,
                products = $('thead td', elem),
                headings;

            if (products.length > this.options.productsInRow) {
                headings = $('<table></table>')
                    .addClass('comparison headings data table')
                    .insertBefore(elem.closest('.container'));

                elem.addClass('scroll');

                $('th', elem).each(function () {
                    var th = $(this),
                        thCopy = th.clone();

                    th.animate({
                        top: '+=0'
                    }, 50, function () {
                        var height = th.height();

                        thCopy.css('height', height)
                            .appendTo(headings)
                            .wrap('<tr></tr>');
                    });
                });
            }

            $(this.options.windowPrintSelector).on('click', function (e) {
                e.preventDefault();
                window.print();
            });
        }
    });

    return $.mage.compareList;
});
