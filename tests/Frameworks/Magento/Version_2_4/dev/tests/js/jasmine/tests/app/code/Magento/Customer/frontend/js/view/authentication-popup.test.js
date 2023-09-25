/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/* eslint max-nested-callbacks: 0 */
define(['squire'], function (Squire) {
    'use strict';

    var injector = new Squire(),
        loginAction = jasmine.createSpy(),
        mocks = {
            'Magento_Customer/js/action/login': loginAction,
            'Magento_Customer/js/customer-data': {
                get: jasmine.createSpy()
            },
            'Magento_Customer/js/model/authentication-popup': {
                createPopUp: jasmine.createSpy(),
                modalWindow: null
            },
            'Magento_Ui/js/modal/alert': jasmine.createSpy(),
            'mage/url': jasmine.createSpyObj('customerData', ['setBaseUrl'])
        },
        obj;

    loginAction.registerLoginCallback = jasmine.createSpy();

    beforeEach(function (done) {
        window.authenticationPopup = {
            customerRegisterUrl: 'register_url',
            customerForgotPasswordUrl: 'forgot_password_url',
            autocomplete: 'autocomplete_flag',
            baseUrl: 'base_url',
            customerLoginUrl:'customer_login_url'
        };

        injector.mock(mocks);
        injector.require(['Magento_Customer/js/view/authentication-popup'], function (Constr) {
            obj = new Constr({
                provider: 'provName',
                name: '',
                index: ''
            });
            done();
        });
    });

    afterEach(function () {
        try {
            injector.clean();
            injector.remove();
        } catch (e) {}
    });

    describe('Magento_Customer/js/view/authentication-popup', function () {
        describe('"isActive" method', function () {
            it('Check for return value.', function () {
                mocks['Magento_Customer/js/customer-data'].get.and.returnValue(function () {
                    return true;
                });
                expect(obj.isActive()).toBeFalsy();
            });
        });
    });

    describe('Magento_Customer/js/view/authentication-popup', function () {
        describe('"setModalElement" method', function () {
            it('Check for return value.', function () {
                expect(obj.setModalElement()).toBeUndefined();
                expect(mocks['Magento_Customer/js/model/authentication-popup'].createPopUp).toHaveBeenCalled();
            });
        });
    });

    describe('Magento_Customer/js/view/authentication-popup', function () {
        describe('"login" method', function () {
            it('Check for return value.', function () {
                var event = {
                    currentTarget: '<form><input type="text" name="username" value="customer"/></form>',
                    stopPropagation: jasmine.createSpy()
                };

                expect(obj.login(null, event)).toBeFalsy();
                expect(mocks['Magento_Customer/js/action/login']).toHaveBeenCalledWith({
                    username: 'customer',
                    customerLoginUrl: 'customer_login_url'
                });
            });
        });
    });
});
