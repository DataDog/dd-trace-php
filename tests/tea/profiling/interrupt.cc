extern "C" {
#include "tea/common.h"
#include "tea/extension.h"
#include "tea/sapi.h"
}

#include <Zend/zend_API.h>
#include <php_config.h>
#include <unistd.h>

#include <tea/testing/catch2.hpp>

#include "profiling.h"

TEA_TEST_CASE_WITH_PROLOGUE(
    "profiling", "profiling interrupt function is called before internal function's post-hook",
    {
        char buffer[1024];
        const char *pwd = getcwd(buffer, sizeof buffer);
        REQUIRE(pwd);

        char fqn[1024];
        int result = snprintf(fqn, sizeof fqn, "%s/tea-profiling.so", pwd);
        REQUIRE(result > 0);
        REQUIRE(result < sizeof buffer);

        REQUIRE(tea_sapi_append_system_ini_entry("extension", "ddtrace.so"));
        REQUIRE(tea_sapi_append_system_ini_entry("zend_extension", fqn));
        REQUIRE(tea_sapi_append_system_ini_entry("datadog.trace.debug", "true"));
        REQUIRE(tea_sapi_append_system_ini_entry("datadog.trace.traced_internal_functions", "sleep"));
    },
    {
        REQUIRE(tea_execute_script("sleep.php"));

        datadog_php_stack_sample sample = tea_get_last_stack_sample();

        /* sample should look something like:
         *   <php>
         *   main
         *   sleep
         */
        REQUIRE(datadog_php_stack_sample_depth(&sample) == 3);

        datadog_php_stack_sample_iterator iter = datadog_php_stack_sample_iterator_ctor(&sample);
        REQUIRE(datadog_php_stack_sample_iterator_valid(&iter));

        // top frame should be sleep, not the ddtrace closure
        auto frame = datadog_php_stack_sample_iterator_frame(&iter);
        auto expected = datadog_php_string_view_from_cstr("sleep");
        REQUIRE(datadog_php_string_view_equal(frame.function, expected));
    })
