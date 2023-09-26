/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
define([
    'jquery',
    'mage/utils/wrapper'
], function ($, wrapper) {
    'use strict';

    var mixin = {

        /**
         * Check if persistent section is expired due to lifetime.
         *
         * @param {Function} originFn - Original method.
         * @return {Array}
         */
        getExpiredSectionNames: function (originFn) {
            var expiredSections = originFn(),
                storage = $.initNamespaceStorage('mage-cache-storage').localStorage,
                currentTimestamp = Math.floor(Date.now() / 1000),
                persistentIndex = expiredSections.indexOf('persistent'),
                persistentLifeTime = 0,
                sectionData;

            if (window.persistent !== undefined && window.persistent.expirationLifetime !== undefined) {
                persistentLifeTime = window.persistent.expirationLifetime;
            }

            if (persistentIndex !== -1) {
                sectionData = storage.get('persistent');

                if (typeof sectionData === 'object' &&
                    sectionData['data_id'] + persistentLifeTime >= currentTimestamp
                ) {
                    expiredSections.splice(persistentIndex, 1);
                }
            }

            return expiredSections;
        },

        /**
         * @param {Object} settings
         * @constructor
         */
        'Magento_Customer/js/customer-data': function (originFn) {
            let mageCacheTimeout = new Date($.localStorage.get('mage-cache-timeout')),
                mageCacheSessId = $.cookieStorage.isSet('mage-cache-sessid');

            originFn();
            if (window.persistent !== undefined && (mageCacheTimeout < new Date() || !mageCacheSessId)) {
                this.reload(['persistent','cart'],true);
            }
        }
    };

    /**
     * Override default customer-data.getExpiredSectionNames().
     */
    return function (target) {
        return wrapper.extend(target, mixin);
    };
});
