/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
/* inspired by http://github.com/requirejs/text */
/*global XMLHttpRequest, XDomainRequest */

define(['module'], function (module) {
    'use strict';

    var xmlRegExp = /^\s*<\?xml(\s)+version=[\'\"](\d)*.(\d)*[\'\"](\s)*\?>/im,
        bodyRegExp = /<body[^>]*>\s*([\s\S]+)\s*<\/body>/im,
        stripReg = /!strip$/i,
        defaultConfig = module.config && module.config() || {};

    /**
     * Strips <?xml ...?> declarations so that external SVG and XML documents can be
     * added to a document without worry.
     * Also, if the string is an HTML document, only the part inside the body tag is returned.
     *
     * @param {String} external
     * @returns {String}
     */
    function stripContent(external) {
        var matches;

        if (!external) {
            return '';
        }

        matches = external.match(bodyRegExp);
        external = matches ?
            matches[1] :
            external.replace(xmlRegExp, '');

        return external;
    }

    /**
     * Checks that url match current location
     *
     * @param {String} url
     * @returns {Boolean}
     */
    function sameDomain(url) {
        var uProtocol, uHostName, uPort,
            xdRegExp = /^([\w:]+)?\/\/([^\/\\]+)/i,
            location = window.location,
            match = xdRegExp.exec(url);

        if (!match) {
            return true;
        }
        uProtocol = match[1];
        uHostName = match[2];

        uHostName = uHostName.split(':');
        uPort = uHostName[1] || '';
        uHostName = uHostName[0];

        return (!uProtocol || uProtocol === location.protocol) &&
            (!uHostName || uHostName.toLowerCase() === location.hostname.toLowerCase()) &&
            (!uPort && !uHostName || uPort === location.port);
    }

    /**
     * @returns {XMLHttpRequest|XDomainRequest|null}
     */
    function createRequest(url) {
        var xhr = new XMLHttpRequest();

        if (!sameDomain(url) && typeof XDomainRequest !== 'undefined') {
            xhr = new XDomainRequest();
        }

        return xhr;
    }

    /**
     * XHR requester. Returns value to callback.
     *
     * @param {String} url
     * @param {Function} callback
     * @param {Function} fail
     * @param {Object} headers
     */
    function getContent(url, callback, fail, headers) {
        var xhr = createRequest(url),
            header;

        xhr.open('GET', url);

        /*eslint-disable max-depth */
        if ('setRequestHeader' in xhr && headers) {
            for (header in headers) {
                if (headers.hasOwnProperty(header)) {
                    xhr.setRequestHeader(header.toLowerCase(), headers[header]);
                }
            }
        }

        /**
         * @inheritdoc
         */
        xhr.onreadystatechange = function () {
            var status, err;

            //Do not explicitly handle errors, those should be
            //visible via console output in the browser.
            if (xhr.readyState === 4) {
                status = xhr.status || 0;

                if (status > 399 && status < 600) {
                    //An http 4xx or 5xx error. Signal an error.
                    err = new Error(url + ' HTTP status: ' + status);
                    err.xhr = xhr;

                    if (fail) {
                        fail(err);
                    }
                } else {
                    callback(xhr.responseText);

                    if (defaultConfig.onXhrComplete) {
                        defaultConfig.onXhrComplete(xhr, url);
                    }
                }
            }
        };

        /*eslint-enable max-depth */

        if (defaultConfig.onXhr) {
            defaultConfig.onXhr(xhr, url);
        }

        xhr.send();
    }

    /**
     * Main method used by RequireJs.
     *
     * @param {String} name - has format: some.module.filext!strip
     * @param {Function} req
     * @param {Function|undefined} onLoad
     */
    function loadContent(name, req, onLoad) {

        var toStrip = stripReg.test(name),
            url = req.toUrl(name.replace(stripReg, '')),
            headers = defaultConfig.headers;

        getContent(url, function (content) {
                content = toStrip ? stripContent(content) : content;
                onLoad(content);
            }, onLoad.error, headers);
    }

    return {
        load: loadContent,
        get: getContent
    };
});
