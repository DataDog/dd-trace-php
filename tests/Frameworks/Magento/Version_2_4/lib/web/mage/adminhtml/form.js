/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/* global varienGlobalEvents, varienWindowOnloadCache, RegionUpdater, FormElementDependenceController */
/* eslint-disable strict */
define([
    'jquery',
    'prototype',
    'mage/adminhtml/events'
], function (jQuery) {
    var varienElementMethods;

    /*
     * @TODO Need to be removed after refactoring all dependent of the form the components
     */
    (function ($) {
        $(function () {
            $(document).on('beforeSubmit', function (e) { //eslint-disable-line max-nested-callbacks
                if (typeof varienGlobalEvents !== 'undefined') {
                    varienGlobalEvents.fireEvent('formSubmit', $(e.target).attr('id'));
                }
            });
        });
    })(jQuery);

    /**
     *  Additional elements methods
     */
    varienElementMethods = {
        /**
         * @param {HTMLElement} element
         */
        setHasChanges: function (element) {
            var elm;

            if ($(element) && $(element).hasClassName('no-changes')) {
                return;
            }
            elm = element;

            while (elm && elm.tagName != 'BODY') { //eslint-disable-line eqeqeq
                if (elm.statusBar) {
                    Element.addClassName($(elm.statusBar), 'changed');
                }
                elm = elm.parentNode;
            }
        },

        /**
         * @param {HTMLElement} element
         * @param {*} flag
         * @param {Object} form
         */
        setHasError: function (element, flag, form) {
            var elm = element;

            while (elm && elm.tagName != 'BODY') { //eslint-disable-line eqeqeq
                if (elm.statusBar) {
                    /* eslint-disable max-depth */
                    if (form.errorSections.keys().indexOf(elm.statusBar.id) < 0) {
                        form.errorSections.set(elm.statusBar.id, flag);
                    }

                    if (flag) {
                        Element.addClassName($(elm.statusBar), 'error');

                        if (form.canShowError && $(elm.statusBar).show) {
                            form.canShowError = false;
                            $(elm.statusBar).show();
                        }
                        form.errorSections.set(elm.statusBar.id, flag);
                    } else if (!form.errorSections.get(elm.statusBar.id)) {
                        Element.removeClassName($(elm.statusBar), 'error');
                    }

                    /* eslint-enable max-depth */
                }
                elm = elm.parentNode;
            }
            this.canShowElement = false;
        }
    };

    Element.addMethods(varienElementMethods);

    // Global bind changes
    window.varienWindowOnloadCache = {};

    /**
     * @param {*} useCache
     */
    function varienWindowOnload(useCache) {
        var dataElements = $$('input', 'select', 'textarea'),
            i;

        for (i = 0; i < dataElements.length; i++) {
            if (dataElements[i] && dataElements[i].id) {

                /* eslint-disable max-depth */
                if (!useCache || !varienWindowOnloadCache[dataElements[i].id]) {
                    Event.observe(dataElements[i], 'change', dataElements[i].setHasChanges.bind(dataElements[i]));

                    if (useCache) {
                        varienWindowOnloadCache[dataElements[i].id] = true;
                    }
                }

                /* eslint-disable max-depth */
            }
        }
    }
    Event.observe(window, 'load', varienWindowOnload);

    window.RegionUpdater = Class.create();
    RegionUpdater.prototype = {
        /**
         * @param {HTMLElement} countryEl
         * @param {HTMLElement} regionTextEl
         * @param {HTMLElement}regionSelectEl
         * @param {Object} regions
         * @param {*} disableAction
         * @param {*} clearRegionValueOnDisable
         */
        initialize: function (
            countryEl, regionTextEl, regionSelectEl, regions, disableAction, clearRegionValueOnDisable
        ) {
            this.isRegionRequired = true;
            this.countryEl = $(countryEl);
            this.regionTextEl = $(regionTextEl);
            this.regionSelectEl = $(regionSelectEl);
            this.config = regions.config;
            delete regions.config;
            this.regions = regions;
            this.sortedRegions = this.getSortedRegions();
            this.disableAction = typeof disableAction === 'undefined' ? 'hide' : disableAction;
            this.clearRegionValueOnDisable = typeof clearRegionValueOnDisable === 'undefined' ?
                false : clearRegionValueOnDisable;

            if (this.regionSelectEl.options.length <= 1) {
                this.update();
            } else {
                this.lastCountryId = this.countryEl.value;
            }

            this.countryEl.changeUpdater = this.update.bind(this);

            Event.observe(this.countryEl, 'change', this.update.bind(this));
        },

        /**
         * @private
         */
        _checkRegionRequired: function () {
            var label, wildCard, elements, that, regionRequired;

            if (!this.isRegionRequired) {
                return;
            }

            elements = [this.regionTextEl, this.regionSelectEl];
            that = this;

            if (typeof this.config == 'undefined') {
                return;
            }
            regionRequired = this.config['regions_required'].indexOf(this.countryEl.value) >= 0;

            elements.each(function (currentElement) {
                var form, validationInstance, field, topElement;

                if (!currentElement) {
                    return;
                }
                form = currentElement.form;
                validationInstance = form ? jQuery(form).data('validation') : null;
                field = currentElement.up('.field') || new Element('div');

                if (validationInstance) {
                    validationInstance.clearError(currentElement);
                }
                label = $$('label[for="' + currentElement.id + '"]')[0];

                if (label) {
                    wildCard = label.down('em') || label.down('span.required');
                    topElement = label.up('tr') || label.up('li');

                    if (!that.config['show_all_regions'] && topElement) {
                        if (regionRequired) {
                            topElement.show();
                        } else {
                            topElement.hide();
                        }
                    }
                }

                if (label && wildCard) {
                    if (!regionRequired) {
                        wildCard.hide();
                    } else {
                        wildCard.show();
                    }
                }

                //compute the need for the required fields
                if (!regionRequired || !currentElement.visible()) {
                    if (field.hasClassName('required')) {
                        field.removeClassName('required');
                    }

                    if (currentElement.hasClassName('required-entry')) {
                        currentElement.removeClassName('required-entry');
                    }

                    if (currentElement.tagName.toLowerCase() == 'select' && //eslint-disable-line eqeqeq
                        currentElement.hasClassName('validate-select')
                    ) {
                        currentElement.removeClassName('validate-select');
                    }
                } else {
                    if (!field.hasClassName('required')) {
                        field.addClassName('required');
                    }

                    if (!currentElement.hasClassName('required-entry')) {
                        currentElement.addClassName('required-entry');
                    }

                    if (currentElement.tagName.toLowerCase() == 'select' && //eslint-disable-line eqeqeq
                        !currentElement.hasClassName('validate-select')
                    ) {
                        currentElement.addClassName('validate-select');
                    }
                }
            });
        },

        /**
         * Disable region validation.
         */
        disableRegionValidation: function () {
            this.isRegionRequired = false;
        },

        /**
         * Update.
         */
        update: function () {
            var option, selectElement, def, regionId, region;

            selectElement = this.regionSelectEl;

            if (this.sortedRegions[this.countryEl.value]) {
                if (this.lastCountryId != this.countryEl.value) { //eslint-disable-line eqeqeq
                    def = selectElement.getAttribute('defaultValue');

                    if (this.regionTextEl) {
                        if (!def) {
                            def = this.regionTextEl.value.toLowerCase();
                        }
                        this.regionTextEl.value = '';
                    }

                    selectElement.options.length = 1;

                    this.sortedRegions[this.countryEl.value].forEach(function (item) {
                        regionId = item[0];
                        region = item[1];

                        option = document.createElement('OPTION');
                        option.value = regionId;
                        option.text = region.name.stripTags();
                        option.title = region.name;

                        if (selectElement.options.add) {
                            selectElement.options.add(option);
                        } else {
                            selectElement.appendChild(option);
                        }

                        if (regionId == def || region.name.toLowerCase() == def || region.code.toLowerCase() == def) { //eslint-disable-line
                            selectElement.value = regionId;
                        }
                    });
                }

                if (this.disableAction == 'hide') { //eslint-disable-line eqeqeq
                    if (this.regionTextEl) {
                        this.regionTextEl.style.display = 'none';
                        this.regionTextEl.style.disabled = true;
                    }
                    this.regionSelectEl.style.display = '';
                    this.regionSelectEl.disabled = false;
                } else if (this.disableAction == 'disable') { //eslint-disable-line eqeqeq
                    if (this.regionTextEl) {
                        this.regionTextEl.disabled = true;
                    }
                    this.regionSelectEl.disabled = false;
                }
                this.setMarkDisplay(this.regionSelectEl, true);

                this.lastCountryId = this.countryEl.value;
            } else {
                if (this.disableAction == 'hide') { //eslint-disable-line eqeqeq
                    if (this.regionTextEl) {
                        this.regionTextEl.style.display = '';
                        this.regionTextEl.style.disabled = false;
                    }
                    this.regionSelectEl.style.display = 'none';
                    this.regionSelectEl.disabled = true;
                } else if (this.disableAction == 'disable') { //eslint-disable-line eqeqeq
                    if (this.regionTextEl) {
                        this.regionTextEl.disabled = false;
                    }
                    this.regionSelectEl.disabled = true;

                    if (this.clearRegionValueOnDisable) {
                        this.regionSelectEl.value = '';
                    }
                } else if (this.disableAction == 'nullify') { //eslint-disable-line eqeqeq
                    this.regionSelectEl.options.length = 1;
                    this.regionSelectEl.value = '';
                    this.regionSelectEl.selectedIndex = 0;
                    this.lastCountryId = '';
                }
                this.setMarkDisplay(this.regionSelectEl, false);
            }
            varienGlobalEvents.fireEvent('address_country_changed', this.countryEl);
            this._checkRegionRequired();
        },

        /**
         * @param {HTMLElement} elem
         * @param {*} display
         */
        setMarkDisplay: function (elem, display) {
            var marks;

            if (elem.parentNode.parentNode) {
                marks = Element.select(elem.parentNode.parentNode, '.required');

                if (marks[0]) {
                    display ? marks[0].show() : marks[0].hide();
                }
            }
        },

        /**
         * Sort regions from JSON by name
         *
         * @returns {*[]}
         */
        getSortedRegions: function () {
            var country, regionsEntries, regionsByCountry;

            regionsByCountry = [];

            for (country in this.regions) { //eslint-disable-line guard-for-in
                regionsEntries = Object.entries(this.regions[country]);
                regionsEntries.sort(function (a, b) {
                    return a[1].name > b[1].name ? 1 : -1;
                });

                regionsByCountry[country] = regionsEntries;
            }

            return regionsByCountry;
        }
    };

    window.regionUpdater = RegionUpdater;

    /**
     * Fix errorrs in IE
     */
    Event.pointerX = function (event) {
        try {
            return event.pageX || (event.clientX + (document.documentElement.scrollLeft || document.body.scrollLeft)); //eslint-disable-line
        }
        catch (e) {}
    };

    /**
     * @param {jQuery.Event} event
     * @return {*}
     */
    Event.pointerY = function (event) {
        try {
            return event.pageY || (event.clientY + (document.documentElement.scrollTop || document.body.scrollTop)); //eslint-disable-line
        }
        catch (e) {}
    };

    /**
     * Observer that watches for dependent form elements
     * If an element depends on 1 or more of other elements,
     * it should show up only when all of them gain specified values
     */
    window.FormElementDependenceController = Class.create();
    FormElementDependenceController.prototype = {
        /**
         * Structure of elements: {
         *     'id_of_dependent_element' : {
         *         'id_of_master_element_1' : 'reference_value',
         *         'id_of_master_element_2' : 'reference_value'
         *         'id_of_master_element_3' : ['reference_value1', 'reference_value2']
         *         ...
         *     }
         * }
         * @param {Object} elementsMap
         * @param {Object} config
         */
        initialize: function (elementsMap, config) {
            var idTo, idFrom, values, fromId, radioFrom;

            if (config) {
                this._config = jQuery.extend(this._config, config);
            }

            for (idTo in elementsMap) { //eslint-disable-line guard-for-in
                for (idFrom in elementsMap[idTo]) { //eslint-disable-line guard-for-in
                    if ($(idFrom)) {
                        Event.observe(
                            $(idFrom),
                            'change',
                            this.trackChange.bindAsEventListener(this, idTo, elementsMap[idTo])
                        );
                    } else {
                        // Check if radio button
                        values = elementsMap[idTo][idFrom].values;
                        fromId = $(idFrom + values[0]);
                        radioFrom = fromId ? $$('[name="' + fromId.name + '"]') : false;

                        if (radioFrom) {
                            radioFrom.invoke(
                                'on',
                                'change',
                                this.trackChange.bindAsEventListener(this, idTo, elementsMap[idTo])
                            );
                        }
                    }
                    this.trackChange(null, idTo, elementsMap[idTo]);
                }
            }
        },

        /**
         * Misc. config options
         * Keys are underscored intentionally
         */
        _config: {
            'levels_up': 1 // how many levels up to travel when toggling element
        },

        /**
         * Define whether target element should be toggled and show/hide its row
         *
         * @param {Object} e - event
         * @param {String} idTo - id of target element
         * @param {Object} valuesFrom - ids of master elements and reference values
         * @return
         */
        trackChange: function (e, idTo, valuesFrom) {
            // define whether the target should show up
            var shouldShowUp = true,
                idFrom, from, values, isInArray, isNegative, headElement, isInheritCheckboxChecked, target, inputs,
                isAnInputOrSelect, currentConfig, rowElement, fromId, radioFrom, targetArray, isChooser;

            for (idFrom in valuesFrom) { //eslint-disable-line guard-for-in
                from = $(idFrom);

                if (from) {
                    values = valuesFrom[idFrom].values;
                    isInArray = values.indexOf(from.value) != -1; //eslint-disable-line
                    isNegative = valuesFrom[idFrom].negative;

                    if (!from || isInArray && isNegative || !isInArray && !isNegative) {
                        shouldShowUp = false;
                    }
                // Check if radio button
                } else {
                    values = valuesFrom[idFrom].values;
                    fromId = $(idFrom + values[0]);

                    if (fromId) {
                        radioFrom = $$('[name="' + fromId.name + '"]:checked');
                        isInArray = radioFrom.length > 0 && values.indexOf(radioFrom[0].value) !== -1;
                        isNegative = valuesFrom[idFrom].negative;

                        if (!radioFrom || isInArray && isNegative || !isInArray && !isNegative) {
                            shouldShowUp = false;
                        }
                    }
                }
            }

            // toggle target row
            headElement = jQuery('#' + idTo + '-head');
            isInheritCheckboxChecked = $(idTo + '_inherit') && $(idTo + '_inherit').checked;
            target = $(idTo);

            // Account for the chooser style parameters.
            if (target === null && headElement.length === 0 && idTo.substring(0, 16) === 'options_fieldset') {
                targetArray = $$('input[id*="' + idTo + '"]');
                isChooser = true;

                if (targetArray !== null && targetArray.length > 0) {
                    target = targetArray[0];
                }
                headElement = jQuery('.field-' + idTo).add('.field-chooser' + idTo);
            }

            // Target won't always exist (for example, if field type is "label")
            if (target) {
                inputs = target.up(this._config['levels_up']).select('input', 'select', 'td');
                isAnInputOrSelect = ['input', 'select'].indexOf(target.tagName.toLowerCase()) != -1; //eslint-disable-line

                if (target.type === 'fieldset') {
                    inputs = target.select('input', 'select', 'td');
                }
            } else {
                inputs = false;
                isAnInputOrSelect = false;
            }

            if (shouldShowUp) {
                currentConfig = this._config;

                if (inputs) {
                    inputs.each(function (item) {
                        // don't touch hidden inputs (and Use Default inputs too), bc they may have custom logic
                        if ((!item.type || item.type != 'hidden') && !($(item.id + '_inherit') && $(item.id + '_inherit').checked) && //eslint-disable-line
                            !(currentConfig['can_edit_price'] != undefined && !currentConfig['can_edit_price']) && //eslint-disable-line
                            !item.getAttribute('readonly') || isChooser //eslint-disable-line
                        ) {
                            item.disabled = false;
                            jQuery(item).removeClass('ignore-validate');
                        }
                    });
                }

                if (headElement.length > 0) {
                    headElement.show();

                    if (headElement.hasClass('open') && target) {
                        target.show();
                    } else if (target) {
                        target.hide();
                    }
                } else {
                    if (target) {
                        target.show();
                        headElement = jQuery('.field-' + idTo).add('.field-chooser' + idTo);
                        headElement.show();
                    }

                    if (isAnInputOrSelect && !isInheritCheckboxChecked) {
                        if (target) {
                            if (target.getAttribute('readonly')) {
                                target.disabled = true;
                            } else {
                                target.disabled = false;
                            }
                        }

                        jQuery('#' + idTo).removeClass('ignore-validate');
                    }
                }
            } else {
                if (inputs) {
                    inputs.each(function (item) {
                        // don't touch hidden inputs (and Use Default inputs too), bc they may have custom logic
                        if ((!item.type || item.type != 'hidden') && //eslint-disable-line eqeqeq
                            !($(item.id + '_inherit') && $(item.id + '_inherit').checked)
                        ) {
                            item.disabled = true;
                            jQuery(item).addClass('ignore-validate');
                        }
                    });
                }

                if (headElement.length > 0) {
                    headElement.hide();
                } else {
                    headElement = jQuery('.field-' + idTo).add('.field-chooser' + idTo);
                    headElement.hide();
                }

                if (target) {
                    target.hide();
                }

                if (isAnInputOrSelect && !isInheritCheckboxChecked) {
                    if (target) {
                        target.disabled = true;
                    }
                    jQuery('#' + idTo).addClass('ignore-validate');
                }

            }
            rowElement = $('row_' + idTo);

            if (rowElement == undefined && target) { //eslint-disable-line eqeqeq
                rowElement = target.up(this._config['levels_up']);

                if (target.type === 'fieldset') {
                    rowElement = target;
                }
            }

            if (rowElement) {
                if (shouldShowUp) {
                    rowElement.show();
                } else {
                    rowElement.hide();
                }
            }
        }
    };

    window.varienWindowOnload = varienWindowOnload;
    window.varienElementMethods = varienElementMethods;
});
