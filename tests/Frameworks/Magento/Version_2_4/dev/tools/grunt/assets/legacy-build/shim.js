/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
(function () {
    'use strict';

    var globals = ['Prototype', 'Abstract', 'Try', 'Class', 'PeriodicalExecuter', 'Template', '$break', 'Enumerable', '$A', '$w', '$H', 'Hash', '$R', 'ObjectRange', 'Ajax', '$', 'Form', 'Field', '$F', 'Toggle', 'Insertion', '$continue', 'Position', 'Windows', 'Dialog', 'array', 'WindowUtilities', 'Builder', 'Effect', 'validateCreditCard', 'Validator', 'Validation', 'removeDelimiters', 'parseNumber', 'popWin', 'setLocation', 'setPLocation', 'setLanguageCode', 'decorateGeneric', 'decorateTable', 'decorateList', 'decorateDataList', 'parseSidUrl', 'formatCurrency', 'expandDetails', 'isIE', 'Varien', 'fireEvent', 'modulo', 'byteConvert', 'SessionError', 'varienLoader', 'varienLoaderHandler', 'setLoaderPosition', 'toggleSelectsUnderBlock', 'varienUpdater', 'setElementDisable', 'toggleParentVis', 'toggleFieldsetVis', 'toggleVis', 'imagePreview', 'checkByProductPriceType', 'toggleSeveralValueElements', 'toggleValueElements', 'submitAndReloadArea', 'syncOnchangeValue', 'updateElementAtCursor', 'firebugEnabled', 'disableElement', 'enableElement', 'disableElements', 'enableElements', 'Cookie', 'Fieldset', 'Base64', 'sortNumeric', 'Element', '$$', 'Sizzle', 'Selector', 'Window'];

    globals.forEach(function (prop) {
        window[prop] = eval(prop);
    });
})();
