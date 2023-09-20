/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

'use strict';

var tasks = [],
    _ = require('underscore');

function init(grunt, options) {
    var _                   = require('underscore'),
        stripJsonComments   = require('strip-json-comments'),
        path                = require('path'),
        config,
        themes,
        file;

    config = grunt.file.read(__dirname + '/settings.json');
    config = stripJsonComments(config);
    config = JSON.parse(config);

    themes = require(path.resolve(process.cwd(), config.themes));

    if (options.theme) {
        themes = _.pick(themes, options.theme);
    }

    tasks = Object.keys(themes);

    config.themes = themes;

    file = grunt.option('file');

    if (file) {
        config.singleTest = file;
    }

    enableTasks(grunt, config);
}

function enableTasks(grunt, config) {
    var jasmine = require('./tasks/jasmine'),
        connect = require('./tasks/connect');

    jasmine.init(config);
    connect.init(config);

    grunt.initConfig({
        jasmine: jasmine.getTasks(),
        connect: connect.getTasks()
    });
}

function getTasks() {
    tasks = tasks.map(function (theme) {
        return [
            'connect:' + theme,
            'jasmine:' + theme
        ]
    });

    return _.flatten(tasks);
}

module.exports = {
    init: init,
    getTasks: getTasks
};
