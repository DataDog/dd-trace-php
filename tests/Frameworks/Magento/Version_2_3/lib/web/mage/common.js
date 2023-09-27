/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define([
    'jquery',
    'domReady!'
], function ($) {
    'use strict';

    /* Form with auto submit feature */
    $('form[data-auto-submit="true"]').submit();

    //Add form keys.
    $(document).on(
        'submit',
        'form',
        function (e) {
            var formKeyElement,
                existingFormKeyElement,
                isKeyPresentInForm,
                isActionExternal,
                baseUrl = window.BASE_URL,
                form = $(e.target),
                formKey = $('input[name="form_key"]').val(),
                formMethod = form.prop('method'),
                formAction = form.prop('action');

            isActionExternal = formAction.indexOf(baseUrl) !== 0;

            existingFormKeyElement = form.find('input[name="form_key"]');
            isKeyPresentInForm = existingFormKeyElement.length;

            /* Verifies that existing auto-added form key is a direct form child element,
               protection from a case when one form contains another form. */
            if (isKeyPresentInForm && existingFormKeyElement.attr('auto-added-form-key') === '1') {
                isKeyPresentInForm = form.find('> input[name="form_key"]').length;
            }

            if (formKey && !isKeyPresentInForm && !isActionExternal && formMethod !== 'get') {
                formKeyElement = document.createElement('input');
                formKeyElement.setAttribute('type', 'hidden');
                formKeyElement.setAttribute('name', 'form_key');
                formKeyElement.setAttribute('value', formKey);
                formKeyElement.setAttribute('auto-added-form-key', '1');
                form.get(0).appendChild(formKeyElement);
            }
        }
    );
});
