/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define([
    'squire'
], function (Squire) {
    'use strict';

    var injector = new Squire(),
        mocks = {
            'Magento_Checkout/js/action/set-payment-information': jasmine.createSpy('placeOrderAction'),
            'Magento_CheckoutAgreements/js/model/agreements-assigner': jasmine.createSpy('agreementsAssigner')
        },
        defaultContext = require.s.contexts._,
        mixin,
        placeOrderAction;

    beforeEach(function (done) {
        window.checkoutConfig = {
            checkoutAgreements: {
                isEnabled: true
            }
        };
        injector.mock(mocks);
        injector.require([
            'Magento_CheckoutAgreements/js/model/set-payment-information-mixin',
            'Magento_Checkout/js/action/set-payment-information'
        ], function (Mixin, setPaymentInformation) {
            mixin = Mixin;
            placeOrderAction = setPaymentInformation;
            done();
        });
    });

    afterEach(function () {
        try {
            injector.clean();
            injector.remove();
        } catch (e) {}
    });

    describe('Magento_CheckoutAgreements/js/model/set-payment-information-mixin', function () {
        it('mixin is applied to Magento_Checkout/js/action/set-payment-information', function () {
            var placeOrderMixins = defaultContext
                .config.config.mixins['Magento_Checkout/js/action/set-payment-information'];

            expect(placeOrderMixins['Magento_CheckoutAgreements/js/model/set-payment-information-mixin']).toBe(true);
        });

        it('Magento_CheckoutAgreements/js/model/agreements-assigner is called', function () {
            var messageContainer = jasmine.createSpy('messageContainer'),
                paymentData = {};

            mixin(placeOrderAction)(messageContainer, paymentData);
            expect(mocks['Magento_CheckoutAgreements/js/model/agreements-assigner'])
                .toHaveBeenCalledWith(paymentData);
            expect(mocks['Magento_Checkout/js/action/set-payment-information'])
                .toHaveBeenCalledWith(messageContainer, paymentData);
        });
    });
});
