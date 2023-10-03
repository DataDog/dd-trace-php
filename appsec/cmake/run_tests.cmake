set(DD_APPSEC_TRACER_EXT_FILE ${CMAKE_SOURCE_DIR}/../tmp/build_extension/modules/ddtrace.so)

add_custom_target(ddtrace
    COMMAND make
    BYPRODUCTS ${DD_APPSEC_TRACER_EXT_FILE}
    WORKING_DIRECTORY ${CMAKE_SOURCE_DIR}/../)

add_custom_target(xtest-prepare
    COMMAND mkdir -p /tmp/appsec-ext-test)

add_custom_target(xtest
    COMMAND ${CMAKE_SOURCE_DIR}/cmake/run-tests-wrapper.sh
    "${CMAKE_BINARY_DIR}" "$<TARGET_FILE:mock_helper>" "${DD_APPSEC_TRACER_EXT_FILE}"
        "${PhpConfig_PHP_BINARY}" -n -d variables_order=EGPCS
        run-tests-internal.php
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

add_dependencies(xtest xtest-prepare ddtrace)

add_subdirectory(tests/mock_helper EXCLUDE_FROM_ALL)
