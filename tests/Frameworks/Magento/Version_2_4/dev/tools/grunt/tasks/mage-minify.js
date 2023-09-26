/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
module.exports = function (grunt) {
    'use strict';

    var compressor  = require('node-minify'),
        _           = require('underscore');

    /**
     * Helper function used to create config object for compressor.
     *
     * @param {Object} options - Options object for a current task.
     * @param {Object} file - File object with 'sorce' and 'destination' properties.
     * @return {Object} Config object for compressor.
     */
    function getConfig(options, file) {
        return _.extend({
            input: file.src,
            output: file.dest
        }, options);
    }

    grunt.registerMultiTask('mage-minify', 'Minify files with a various compressor engines', function () {
        var done = this.async(),
            files = this.files,
            total = files.length,
            options = this.options();

        this.files.forEach(function (file, i) {
            var config = getConfig(options, file);

            /**
             * Callback function.
             */
            config.callback = function (err) {
                if (err) {
                    console.log(err);
                    done(false);
                } else if (i === total - 1) {
                    done();
                }
            };

            compressor.minify(config);
        });
    });
};
