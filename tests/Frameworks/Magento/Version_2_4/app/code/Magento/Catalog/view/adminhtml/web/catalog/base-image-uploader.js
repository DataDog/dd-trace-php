/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define([
    'jquery',
    'mage/template',
    'Magento_Ui/js/modal/alert',
    'jquery/ui',
    'jquery/file-uploader',
    'mage/translate',
    'mage/backend/notification'
], function ($, mageTemplate, alert) {
    'use strict';

    $.widget('mage.baseImage', {
        /**
         * Button creation
         * @protected
         */
        options: {
            maxImageUploadCount: 10
        },

        /** @inheritdoc */
        _create: function () {
            var $container = this.element,
                imageTmpl = mageTemplate(this.element.find('[data-template=image]').html()),
                $dropPlaceholder = this.element.find('.image-placeholder'),
                $galleryContainer = $('#media_gallery_content'),
                mainClass = 'base-image',
                maximumImageCount = 5,
                $fieldCheckBox = $container.closest('[data-attribute-code=image]').find(':checkbox'),
                isDefaultChecked = $fieldCheckBox.is(':checked'),
                findElement, updateVisibility;

            if (isDefaultChecked) {
                $fieldCheckBox.trigger('click');
            }

            /**
             * @param {Object} data
             * @return {HTMLElement}
             */
            findElement = function (data) {
                return $container.find('.image:not(.image-placeholder)').filter(function () {
                    if (!$(this).data('image')) {
                        return false;
                    }

                    return $(this).data('image').file === data.file;
                }).first();
            };

            /** Update image visibility. */
            updateVisibility = function () {
                var elementsList = $container.find('.image:not(.removed-item)');

                elementsList.each(function (index) {
                    $(this)[index < maximumImageCount ? 'show' : 'hide']();
                });
                $dropPlaceholder[elementsList.length > maximumImageCount ? 'hide' : 'show']();
            };

            $galleryContainer.on('setImageType', function (event, data) {
                if (data.type === 'image') {
                    $container.find('.' + mainClass).removeClass(mainClass);

                    if (data.imageData) {
                        findElement(data.imageData).addClass(mainClass);
                    }
                }
            });

            $galleryContainer.on('addItem', function (event, data) {
                var tmpl = imageTmpl({
                    data: data
                });

                $(tmpl).data('image', data).insertBefore($dropPlaceholder);

                updateVisibility();
            });

            $galleryContainer.on('removeItem', function (event, image) {
                findElement(image).addClass('removed-item').hide();
                updateVisibility();
            });

            $galleryContainer.on('moveElement', function (event, data) {
                var $element = findElement(data.imageData),
                    $after;

                if (data.position === 0) {
                    $container.prepend($element);
                } else {
                    $after = $container.find('.image').eq(data.position);

                    if (!$element.is($after)) {
                        $element.insertAfter($after);
                    }
                }
                updateVisibility();
            });

            $container.on('click', '[data-role=make-base-button]', function (event) {
                var data;

                event.preventDefault();
                data = $(event.target).closest('.image').data('image');
                $galleryContainer.productGallery('setBase', data);
            });

            $container.on('click', '[data-role=delete-button]', function (event) {
                event.preventDefault();
                $galleryContainer.trigger('removeItem', $(event.target).closest('.image').data('image'));
            });

            $container.sortable({
                axis: 'x',
                items: '.image:not(.image-placeholder)',
                distance: 8,
                tolerance: 'pointer',

                /**
                 * @param {jQuery.Event} event
                 * @param {Object} data
                 */
                stop: function (event, data) {
                    $galleryContainer.trigger('setPosition', {
                        imageData: data.item.data('image'),
                        position: $container.find('.image').index(data.item)
                    });
                    $galleryContainer.trigger('resort');
                }
            }).disableSelection();

            this.element.find('input[type="file"]').fileupload({
                dataType: 'json',
                dropZone: $dropPlaceholder.closest('[data-attribute-code]'),
                acceptFileTypes: /(\.|\/)(gif|jpe?g|png)$/i,
                maxFileSize: this.element.data('maxFileSize'),

                /**
                 * @param {jQuery.Event} event
                 * @param {Object} data
                 */
                done: function (event, data) {
                    $dropPlaceholder.find('.progress-bar').text('').removeClass('in-progress');

                    if (!data.result) {
                        return;
                    }

                    if (!data.result.error) {
                        $galleryContainer.trigger('addItem', data.result);
                    } else {
                        alert({
                            content: $.mage.__('We don\'t recognize or support this file extension type.')
                        });
                    }
                },

                /**
                 * @param {jQuery.Event} e
                 * @param {Object} data
                 */
                change: function (e, data) {
                    if (data.files.length > this.options.maxImageUploadCount) {
                        $('body').notification('clear').notification('add', {
                            error: true,
                            message: $.mage.__('You can\'t upload more than ' + this.options.maxImageUploadCount +
                                ' images in one time'),

                            /**
                             * @param {*} message
                             */
                            insertMethod: function (message) {
                                $('.page-main-actions').after(message);
                            }
                        });

                        return false;
                    }
                }.bind(this),

                /**
                 * @param {jQuery.Event} event
                 * @param {*} data
                 */
                add: function (event, data) {
                    $(this).fileupload('process', data).done(function () {
                        data.submit();
                    });
                },

                /**
                 * @param {jQuery.Event} e
                 * @param {Object} data
                 */
                progress: function (e, data) {
                    var progress = parseInt(data.loaded / data.total * 100, 10);

                    $dropPlaceholder.find('.progress-bar').addClass('in-progress').text(progress + '%');
                },

                /**
                 * @param {jQuery.Event} event
                 */
                start: function (event) {
                    var uploaderContainer = $(event.target).closest('.image-placeholder');

                    uploaderContainer.addClass('loading');
                },

                /**
                 * @param {jQuery.Event} event
                 */
                stop: function (event) {
                    var uploaderContainer = $(event.target).closest('.image-placeholder');

                    uploaderContainer.removeClass('loading');
                }
            });
        }
    });

    return $.mage.baseImage;
});
