/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * @deprecated since version 2.2.0
 */
define([
    'ko',
    'uiComponent'
], function (ko, Component) {
    'use strict';

    var persistenceConfig = window.checkoutConfig.persistenceConfig;

    return Component.extend({
        defaults: {
            template: 'Magento_Persistent/remember-me'
        },
        dataScope: 'global',
        isRememberMeCheckboxVisible: ko.observable(persistenceConfig.isRememberMeCheckboxVisible),
        isRememberMeCheckboxChecked: ko.observable(persistenceConfig.isRememberMeCheckboxChecked)
    });
});
