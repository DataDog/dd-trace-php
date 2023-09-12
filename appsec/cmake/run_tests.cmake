if(NOT DD_APPSEC_TRACER_EXT_FILE)
    set(DD_APPSEC_TRACER_EXT_FILE skip)
endif()

add_custom_target(xtest-prepare
    COMMAND mkdir -p /tmp/appsec-ext-test)

add_custom_target(xtest
    COMMAND ${CMAKE_SOURCE_DIR}/cmake/run-tests-wrapper.sh
    "${CMAKE_BINARY_DIR}" "$<TARGET_FILE:mock_helper>" "${DD_APPSEC_TRACER_EXT_FILE}"
        "${PhpConfig_PHP_BINARY}" -n -d variables_order=EGPCS
        run-tests.php
        -n -c ${CMAKE_SOURCE_DIR}/tests/extension/test-php.ini
        -d "extension_dir=${CMAKE_BINARY_DIR}/extensions"
        -d "extension=$<TARGET_FILE:extension>"
        ${CMAKE_SOURCE_DIR}/tests/extension/
    WORKING_DIRECTORY ${CMAKE_SOURCE_DIR})

add_dependencies(xtest xtest-prepare)

add_subdirectory(tests/mock_helper EXCLUDE_FROM_ALL)
