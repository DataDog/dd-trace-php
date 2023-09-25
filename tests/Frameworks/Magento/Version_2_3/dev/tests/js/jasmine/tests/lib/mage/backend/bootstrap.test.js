/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
/* eslint-disable max-nested-callbacks */
define([
    'jquery',
    'mage/backend/bootstrap'
], function ($) {
    'use strict';

    describe('mage/backend/bootstrap', function () {
        var $pageMainActions;

        beforeEach(function () {
            $pageMainActions = $('<div class="page-main-actions"></div>');
        });

        afterEach(function () {
            $pageMainActions.remove();
        });

        describe('"sendPostponeRequest" method', function () {
            it('should insert "Error" notification if request failed', function () {
                $pageMainActions.appendTo('body');
                $('body').notification();

                $.ajaxSettings.error();

                expect($('.message-error').length).toBe(1);
                expect(
                    $('body:contains("A technical problem with the server created an error")').length
                ).toBe(1);
            });
        });
    });
});
