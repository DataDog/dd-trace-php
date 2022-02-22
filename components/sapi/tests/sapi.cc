extern "C" {
#include <components/sapi/sapi.h>
}

#include <catch2/catch.hpp>

TEST_CASE("recognize real sapis", "[sapi]") {
    // these strings were taken from the PHP 8.0's sapi/ folder
    struct {
        const char *name;
        datadog_php_sapi sapi;
    } servers[] = {
        {"apache2handler", DATADOG_PHP_SAPI_APACHE2HANDLER},
        {"cgi-fcgi", DATADOG_PHP_SAPI_CGI_FCGI},
        {"cli", DATADOG_PHP_SAPI_CLI},
        {"cli-server", DATADOG_PHP_SAPI_CLI_SERVER},
        {"embed", DATADOG_PHP_SAPI_EMBED},
        {"fpm-fcgi", DATADOG_PHP_SAPI_FPM_FCGI},
        {"litespeed", DATADOG_PHP_SAPI_LITESPEED},
        {"phpdbg", DATADOG_PHP_SAPI_PHPDBG},
        {"tea", DATADOG_PHP_SAPI_TEA},
    };

    for (auto server : servers) {
        datadog_php_string_view view = datadog_php_string_view_from_cstr(server.name);
        datadog_php_sapi sapi = datadog_php_sapi_from_name(view);

        REQUIRE(sapi == server.sapi);
    }
}

TEST_CASE("unknown sapis", "[sapi]") {
    /* These used to be SAPIs, but have since been removed. I think that makes
     * them good testing candidates for unknown SAPIs.
     */
    const char *servers[] = {
        "aolserver",
        "caudium",

        // "Continuity" being upper-cased gave me a giggle, as all others aren't
        "Continuity",

        "isapi",
        "nsapi",
        "pi3web",
        "roxen",
        "webjames",
    };

    for (auto server : servers) {
        datadog_php_string_view view = datadog_php_string_view_from_cstr(server);
        datadog_php_sapi sapi = datadog_php_sapi_from_name(view);

        REQUIRE(sapi == DATADOG_PHP_SAPI_UNKNOWN);
    }
}
