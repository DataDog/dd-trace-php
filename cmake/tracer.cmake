option(DD_APPSEC_BUILD_TRACER "Whether to build the tracer from source" ON)
set(DD_APPSEC_TRACER_VERSION "77e083b943458707e9e464d0e957263caa43b089" CACHE STRING "The tracer version to build or download")

add_library(tracer SHARED IMPORTED GLOBAL)

find_extension_api(PHP_CONFIG_ZEND_API)

if(DD_APPSEC_BUILD_TRACER)
    find_program(PHP_CONFIG php-config)
    if(PHP_CONFIG STREQUAL PHP_CONFIG-NOTFOUND)
        message(FATAL_ERROR "Cannot find php-config, either set PHP_CONFIG or make it discoverable")
    endif()

    execute_process(
        COMMAND bash -c "echo $(dirname \"$('${PHP_CONFIG}' --php-binary)\")/phpize"
        RESULT_VARIABLE PHP_CONFIG_PHPIZE_RESULT
        OUTPUT_VARIABLE PHP_CONFIG_PHPIZE
        ERROR_VARIABLE PHP_CONFIG_PHPIZE_ERR
        OUTPUT_STRIP_TRAILING_WHITESPACE
    )

    if(NOT "${PHP_CONFIG_PHPIZE_RESULT}" STREQUAL "0")
        message(FATAL_ERROR "Error obtaining location of phpize: ${PHP_CONFIG_PHPIZE_ERR}")
    endif()

    include(ExternalProject)
    ExternalProject_Add(proj_tracer
        URL         https://github.com/DataDog/dd-trace-php/archive/${DD_APPSEC_TRACER_VERSION}.tar.gz
        PREFIX      proj_tracer-${PHP_CONFIG_ZEND_API}
        CONFIGURE_COMMAND cd <SOURCE_DIR> && ${PHP_CONFIG_PHPIZE} &&
                          cd <BINARY_DIR> && <SOURCE_DIR>/configure --with-php-config=${PHP_CONFIG}
        BUILD_COMMAND VERBOSE=1 make -j
        INSTALL_COMMAND true)


    ExternalProject_Get_property(proj_tracer BINARY_DIR)
    set_property(TARGET tracer PROPERTY IMPORTED_LOCATION ${BINARY_DIR}/modules/ddtrace.so)

    add_dependencies(tracer proj_tracer)
    set_target_properties(proj_tracer PROPERTIES EXCLUDE_FROM_ALL TRUE)
else()
    include(ExternalProject)
    ExternalProject_Add(proj_tracer
        URL     https://github.com/DataDog/dd-trace-php/releases/download/${DD_APPSEC_TRACER_VERSION}/datadog-php-tracer-${DD_APPSEC_TRACER_VERSION}.x86_64.tar.gz
        PREFIX  proj_tracer_release
        CONFIGURE_COMMAND ""
        BUILD_COMMAND ""
        INSTALL_COMMAND "")

    string(FIND ${PHP_CONFIG_ZEND_API} no-debug PHP_NO_DEBUG)
    string(FIND ${PHP_CONFIG_ZEND_API} non-zts PHP_NO_ZTS)
    string(REGEX MATCH "[0-9]+" PHP_API_VERSION ${PHP_CONFIG_ZEND_API})

    if(PHP_NO_DEBUG EQUAL -1 AND PHP_NO_ZTS EQUAL -1)
        message(FATAL_ERROR "No precompiled binary for debug/zts combination")
    endif()

    if(PHP_NO_DEBUG EQUAL -1) # no-debug not found -> debug build
        set(TRACER_SUFFIX -debug)
    elseif(PHP_NO_ZTS EQUAL -1)
        set(TRACER_SUFFIX -zts)
    else()
        set(TRACER_SUFFIX "")
    endif()

    ExternalProject_Get_property(proj_tracer SOURCE_DIR)
    set_property(TARGET tracer PROPERTY IMPORTED_LOCATION ${SOURCE_DIR}/datadog-php/extensions/ddtrace-${PHP_API_VERSION}${TRACER_SUFFIX}.so)

    add_dependencies(tracer proj_tracer)
    set_target_properties(proj_tracer PROPERTIES EXCLUDE_FROM_ALL TRUE)
endif()

set_target_properties(tracer PROPERTIES EXCLUDE_FROM_ALL TRUE)
