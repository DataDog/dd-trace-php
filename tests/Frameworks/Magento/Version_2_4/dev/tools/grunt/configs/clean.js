/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

'use strict';

var themes = require('../tools/files-router').get('themes'),
    _      = require('underscore');

var themeOptions = {};

_.each(themes, function(theme, name) {
    themeOptions[name] = {
        "force": true,
        "files": [
            {
                "force": true,
                "dot": true,
                "src": [
                    "<%= path.tmp %>/cache/**/*",
                    "<%= combo.autopath(\""+name+"\", path.pub ) %>**/*",
                    "<%= combo.autopath(\""+name+"\", path.tmpLess) %>**/*",
                    "<%= combo.autopath(\""+name+"\", path.tmpSource) %>**/*",
                    "<%= path.deployedVersion %>"
                ]
            }
        ]
    };
});

var cleanOptions = {
    "var": {
        "force": true,
        "files": [
            {
                "force": true,
                "dot": true,
                "src": [
                    "<%= path.tmp %>/cache/**/*",
                    "<%= path.tmp %>/log/**/*",
                    "<%= path.tmp %>/maps/**/*",
                    "<%= path.tmp %>/page_cache/**/*",
                    "<%= path.tmp %>/tmp/**/*",
                    "<%= path.tmp %>/view/**/*",
                    "<%= path.tmp %>/view_preprocessed/**/*"
                ]
            }
        ]
    },
    "pub": {
        "force": true,
        "files": [
            {
                "force": true,
                "dot": true,
                "src": [
                    "<%= path.pub %>frontend/**/*",
                    "<%= path.pub %>adminhtml/**/*",
                    "<%= path.deployedVersion %>"
                ]
            }
        ]
    },
    "styles": {
        "force": true,
        "files": [
            {
                "force": true,
                "dot": true,
                "src": [
                    "<%= path.tmp %>/view_preprocessed/**/*",
                    "<%= path.tmp %>/cache/**/*",
                    "<%= path.pub %>frontend/**/*.less",
                    "<%= path.pub %>frontend/**/*.css",
                    "<%= path.pub %>adminhtml/**/*.less",
                    "<%= path.pub %>adminhtml/**/*.css",
                    "<%= path.deployedVersion %>"
                ]
            }
        ]
    },
    "markup": {
        "force": true,
        "files": [
            {
                "force": true,
                "dot": true,
                "src": [
                    "<%= path.tmp %>/cache/**/*",
                    "<%= path.tmp %>/view_preprocessed/html/**/*",
                    "<%= path.tmp %>/page_cache/**/*"
                ]
            }
        ]
    },
    "js": {
        "force": true,
        "files": [
            {
                "force": true,
                "dot": true,
                "src": [
                    "<%= path.pub %>**/*.js",
                    "<%= path.pub %>**/*.html",
                    "<%= path.pub %>_requirejs/**/*",
                    "<%= path.deployedVersion %>"
                ]
            }
        ]
    },
    "generation": {
        "force": true,
        "files": [
            {
                "force": true,
                "dot": true,
                "src": [
                    "<%= path.generation %>code/**/*",
                    "<%= path.generation %>metadata/**/*"
                ]
            }
        ]
    }
};

module.exports = _.extend(cleanOptions, themeOptions);

