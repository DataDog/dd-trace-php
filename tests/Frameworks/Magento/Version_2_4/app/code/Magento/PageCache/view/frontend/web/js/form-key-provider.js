/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
define(function () {
    'use strict';

    return function (settings) {
        var formKey,
            inputElements,
            inputSelector = 'input[name="form_key"]';

        /**
         * Set form_key cookie
         * @private
         */
        function setFormKeyCookie(value) {
            var expires,
                secure,
                date = new Date(),
                cookiesConfig = window.cookiesConfig || {},
                isSecure = !!cookiesConfig.secure,
                samesite = cookiesConfig.samesite || 'lax';

            date.setTime(date.getTime() + 86400000);
            expires = '; expires=' + date.toUTCString();
            secure = isSecure ? '; secure' : '';
            samesite = '; samesite=' + samesite;

            document.cookie = 'form_key=' + (value || '') + expires + secure + '; path=/' + samesite;
        }

        /**
         * Retrieves form key from cookie
         * @private
         */
        function getFormKeyCookie() {
            var cookie,
                i,
                nameEQ = 'form_key=',
                cookieArr = document.cookie.split(';');

            for (i = 0; i < cookieArr.length; i++) {
                cookie = cookieArr[i];

                while (cookie.charAt(0) === ' ') {
                    cookie = cookie.substring(1, cookie.length);
                }

                if (cookie.indexOf(nameEQ) === 0) {
                    return cookie.substring(nameEQ.length, cookie.length);
                }
            }

            return null;
        }

        /**
         * Get form key from UI input hidden
         * @private
         */
        function getFormKeyFromUI() {
            return document.querySelector(inputSelector).value;
        }

        /**
         * Generate form key string
         * @private
         */
        function generateFormKeyString() {
            var result = '',
                length = 16,
                chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

            while (length--) {
                result += chars[Math.round(Math.random() * (chars.length - 1))];
            }

            return result;
        }

        /**
         * Init form_key inputs with value
         * @private
         */
        function initFormKey() {
            formKey = getFormKeyCookie();

            if (settings && settings.isPaginationCacheEnabled && !formKey) {
                formKey = getFormKeyFromUI();
                setFormKeyCookie(formKey);
            }

            if (!formKey) {
                formKey = generateFormKeyString();
                setFormKeyCookie(formKey);
            }
            inputElements = document.querySelectorAll(inputSelector);

            if (inputElements.length) {
                Array.prototype.forEach.call(inputElements, function (element) {
                    element.setAttribute('value', formKey);
                });
            }
        }

        initFormKey();
    };
});
