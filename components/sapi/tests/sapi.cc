extern "C" {
#include "sapi/sapi.h"
}

#include <catch2/catch.hpp>

TEST_CASE("recongize real sapis", "[sapi]") {
    // these strings were taken from the PHP 8.0's sapi/ folder
    struct {
        const char *name;
        datadog_php_sapi sapi;
    } cases[] = {
        {"apache2handler", DATADOG_PHP_SAPI_APACHE2HANDLER},
        {"cgi-fcgi", DATADOG_PHP_SAPI_CGI_FCGI},
        {"cli", DATADOG_PHP_SAPI_CLI},
        {"cli-server", DATADOG_PHP_SAPI_CLI_SERVER},
        {"embed", DATADOG_PHP_SAPI_EMBED},
        {"fpm-fcgi", DATADOG_PHP_SAPI_FPM_FCGI},
        {"litespeed", DATADOG_PHP_SAPI_LITESPEED},
        {"phpdbg", DATADOG_PHP_SAPI_PHPDBG},
    };

    unsigned n_sapis = sizeof cases / sizeof *cases;
    for (unsigned i = 0; i != n_sapis; ++i) {
        datadog_php_string_view view = datadog_php_string_view_from_cstr(cases[i].name);
        datadog_php_sapi sapi = datadog_php_sapi_from_name(view);

        REQUIRE(sapi == cases[i].sapi);
    }
}

TEST_CASE("unknown sapis", "[sapi]") {
    /* These used to be SAPIs, but have since been removed. I think that makes
     * them good testing canidates for unknown SAPIs.
     */
    const char *cases[] = {
        "aolserver",
        "caudium",

        // The fact "Continuity" is upper-cased gave me a giggle
        "Continuity",

        "isapi",
        "nsapi",
        "pi3web",
        "roxen",
        "webjames",
    };

    unsigned n_sapis = sizeof cases / sizeof *cases;
    for (unsigned i = 0; i != n_sapis; ++i) {
        datadog_php_string_view view = datadog_php_string_view_from_cstr(cases[i]);
        datadog_php_sapi sapi = datadog_php_sapi_from_name(view);

        REQUIRE(sapi == DATADOG_PHP_SAPI_UNKNOWN);
    }
}
