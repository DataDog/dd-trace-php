execute_process(
    COMMAND ${PHP_CONFIG} --php-binary
    RESULT_VARIABLE PHP_BINARY_RESULT
    OUTPUT_VARIABLE PHP_BINARY
    ERROR_VARIABLE PHP_BINARY_ERR
    OUTPUT_STRIP_TRAILING_WHITESPACE
    )
if("${PHP_BINARY_RESULT}" STREQUAL "0")
    message(STATUS "PHP binary: ${PHP_BINARY}")
else()
    message(FATAL_ERROR "Error executing ${PHP_CONFIG} --php-binary: ${PHP_BINARY_ERR}")
endif()

if(DD_APPSEC_ENABLE_TRACER)
    set(TRACER_EXT_FILE $<TARGET_FILE:tracer>)
else()
    set(TRACER_EXT_FILE skip)
endif()

add_custom_target(xtest
    COMMAND ${CMAKE_SOURCE_DIR}/cmake/run-tests-wrapper.sh
    "${CMAKE_BINARY_DIR}" "$<TARGET_FILE:mock_helper>" "${TRACER_EXT_FILE}"
        "${PHP_BINARY}" -n -d variables_order=EGPCS
        run-tests.php
        -n -c ${CMAKE_SOURCE_DIR}/tests/extension/test-php.ini
        -d "extension_dir=${CMAKE_BINARY_DIR}/extensions"
        -d "extension=$<TARGET_FILE:extension>"
        ${CMAKE_SOURCE_DIR}/tests/extension/
    WORKING_DIRECTORY ${CMAKE_SOURCE_DIR})

if(DD_APPSEC_ENABLE_COVERAGE)
    add_custom_command(TARGET xtest POST_BUILD
        COMMAND ${CMAKE_COMMAND} -E cmake_echo_color --cyan
        "To generate a coverage HTML report, run "
        "gcovr -r ${CMAKE_SOURCE_DIR} --html --html-details -s -d -o coverage.html")
endif()

add_subdirectory(tests/mock_helper EXCLUDE_FROM_ALL)

# Examples
if(DD_APPSEC_BUILD_HELPER)
    get_filename_component(PHP_BIN_DIR ${PHP_BINARY} DIRECTORY)
    if(EXISTS "${PHP_BIN_DIR}/../lib/libphp7.so")
        set(PHP_APACHE_MODULE "${PHP_BIN_DIR}/../lib/libphp7.so")
    else()
        set(PHP_APACHE_MODULE "${PHP_BIN_DIR}/../lib/libphp.so")
    endif()

    ExternalProject_Get_property(proj_event_rules SOURCE_DIR)
    set(EVENT_RULES_SOURCE_DIR ${SOURCE_DIR})
    add_custom_target(ex_apache_mod
        COMMAND ${CMAKE_SOURCE_DIR}/examples/apache_mod/start.sh
        .
        ${PHP_APACHE_MODULE}
        $<TARGET_FILE:extension>
        $<TARGET_FILE:tracer>
        $<TARGET_FILE:ddappsec-helper>
        ${EVENT_RULES_SOURCE_DIR}/v2/build/recommended.json
        WORKING_DIRECTORY ${CMAKE_BUILD_DIR})
    add_dependencies(ex_apache_mod proj_event_rules)

    add_custom_target(ex_apache_fpm
        COMMAND ${CMAKE_SOURCE_DIR}/examples/apache_fpm/start.sh
        .
        ${PHP_BIN_DIR}/../sbin
        $<TARGET_FILE:extension>
        $<TARGET_FILE:tracer>
        $<TARGET_FILE:ddappsec-helper>
        ${EVENT_RULES_SOURCE_DIR}/v2/build/recommended.json
        WORKING_DIRECTORY ${CMAKE_BUILD_DIR})
    add_dependencies(ex_apache_fpm proj_event_rules)
endif()

# vim: set et:
