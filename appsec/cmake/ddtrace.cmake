include(ExternalProject)

set(DD_APPSEC_LIBDATADOG_PHP "" CACHE FILEPATH
    "Path to a prebuilt libdatadog_php library")
set(DD_APPSEC_PHP_SIDECAR_MOCKGEN "" CACHE FILEPATH
    "Path to a prebuilt php_sidecar_mockgen executable")

if(DD_APPSEC_SSI)
    set(CARGO_BUILD_CMD "cargo build")
else()
    # Only the static library is linked into ddtrace. Building the cdylib as well fails on macOS
    # because it references symbols that are provided by the final ddtrace extension.
    set(CARGO_BUILD_CMD "cargo rustc --lib --crate-type staticlib")
endif()
set(CARGO_BUILD_ENV "") # Initialize to empty


if(CMAKE_BUILD_TYPE STREQUAL "Release")
    set(CARGO_BUILD_CMD "${CARGO_BUILD_CMD} --release")
elseif(CMAKE_BUILD_TYPE STREQUAL "RelWithDebInfo")
    set(CARGO_BUILD_CMD "${CARGO_BUILD_CMD} --release")
    set(CARGO_BUILD_ENV RUSTFLAGS='-C\ debuginfo=2')
endif()

set(LIBDATADOG_DIR "${CMAKE_SOURCE_DIR}/../libdatadog")
set(LIBDATADOG_STAMP_FILE "${CMAKE_BINARY_DIR}/libdatadog.stamp")
set(DDTRACE_EXPORT_VARIANT "slim")
set(DDTRACE_EXPORT_SYMBOL_FILES
    "${CMAKE_SOURCE_DIR}/../ddtrace-extension.sym")
if(${CMAKE_SYSTEM_NAME} STREQUAL "Linux")
    list(APPEND DDTRACE_EXPORT_SYMBOL_FILES
        "${CMAKE_SOURCE_DIR}/../ddtrace-extension-linux.sym")
endif()
if(NOT DD_APPSEC_SSI)
    set(DDTRACE_EXPORT_VARIANT "fat")
    list(APPEND DDTRACE_EXPORT_SYMBOL_FILES
        "${CMAKE_SOURCE_DIR}/../components-rs/libdatadog-php.sym")
    if(UNIX)
        list(APPEND DDTRACE_EXPORT_SYMBOL_FILES
            "${CMAKE_SOURCE_DIR}/../components-rs/libdatadog-php-unix.sym")
    endif()
    if(${CMAKE_SYSTEM_NAME} STREQUAL "Linux")
        list(APPEND DDTRACE_EXPORT_SYMBOL_FILES
            "${CMAKE_SOURCE_DIR}/../components-rs/libdatadog-php-linux.sym")
    endif()
endif()
string(JOIN "' '" DDTRACE_EXPORT_SYMBOL_ARGUMENTS
    ${DDTRACE_EXPORT_SYMBOL_FILES})

add_custom_target(libdatadog_stamp
    COMMAND ${CMAKE_COMMAND}
        "-DCOMPONENTS_RS=${CMAKE_SOURCE_DIR}/../components-rs"
        "-DLIBDATADOG=${LIBDATADOG_DIR}"
        "-DSTAMP_FILE=${LIBDATADOG_STAMP_FILE}"
        -P "${CMAKE_CURRENT_LIST_DIR}/update_rust_stamp.cmake"
    BYPRODUCTS ${LIBDATADOG_STAMP_FILE}
)

if(${CMAKE_SYSTEM_NAME} STREQUAL "Linux")
set(EXPORTS_FILE
    "${CMAKE_BINARY_DIR}/ddtrace-${DDTRACE_EXPORT_VARIANT}.version")
add_custom_target(ddtrace_exports
    COMMAND bash -c "{ echo -e '{\\nglobal:'; sed 's/$/;/' '${DDTRACE_EXPORT_SYMBOL_ARGUMENTS}'; echo -e 'local:\\n*;\\n};'; } > '${EXPORTS_FILE}'"
    BYPRODUCTS ${EXPORTS_FILE}
    DEPENDS ${DDTRACE_EXPORT_SYMBOL_FILES}
    VERBATIM
)
elseif(APPLE)
set(EXPORTS_FILE
    "${CMAKE_BINARY_DIR}/ddtrace-${DDTRACE_EXPORT_VARIANT}.sym")
add_custom_target(ddtrace_exports
    COMMAND bash -c "sed 's/^/_/' '${DDTRACE_EXPORT_SYMBOL_ARGUMENTS}' > '${EXPORTS_FILE}'"
    BYPRODUCTS ${EXPORTS_FILE}
    DEPENDS ${DDTRACE_EXPORT_SYMBOL_FILES}
)
endif()

file(READ "${CMAKE_SOURCE_DIR}/../VERSION" VERSION_CONTENTS)
string(STRIP "${VERSION_CONTENTS}" PHP_DDTRACE_VERSION)
file(MAKE_DIRECTORY "${CMAKE_BINARY_DIR}/gen_ddtrace/ext")
set(VERSION_H_PATH "${CMAKE_BINARY_DIR}/gen_ddtrace/ext/version.h")

add_custom_command(
    OUTPUT "${VERSION_H_PATH}"
    COMMAND ${CMAKE_COMMAND} -E cmake_echo_color --switch= --green "Updating version.h"
    COMMAND ${CMAKE_COMMAND} -E remove -f "${VERSION_H_PATH}"
    COMMAND ${CMAKE_COMMAND} -E touch "${VERSION_H_PATH}"
    COMMAND printf "\\#ifndef PHP_DDTRACE_VERSION\\\\n\\#define PHP_DDTRACE_VERSION \"%s\"\\\\n\\#endif" "'\"${PHP_DDTRACE_VERSION}\"'" >> "${VERSION_H_PATH}"
    DEPENDS "${CMAKE_SOURCE_DIR}/../VERSION"
    COMMENT "Generating version.h"
)
add_custom_target(update_version_h ALL DEPENDS "${VERSION_H_PATH}")

if(DD_APPSEC_LIBDATADOG_PHP)
    if(NOT EXISTS "${DD_APPSEC_LIBDATADOG_PHP}")
        message(FATAL_ERROR
            "Prebuilt libdatadog_php does not exist: ${DD_APPSEC_LIBDATADOG_PHP}")
    endif()

    if(DD_APPSEC_SSI)
        add_library(ddtrace_php SHARED IMPORTED GLOBAL)
        set_target_properties(ddtrace_php PROPERTIES
            IMPORTED_LOCATION "${DD_APPSEC_LIBDATADOG_PHP}")
    else()
        add_library(components_rs STATIC IMPORTED)
        set_target_properties(components_rs PROPERTIES
            IMPORTED_LOCATION "${DD_APPSEC_LIBDATADOG_PHP}")
    endif()
else()
    ExternalProject_Add(components_rs_proj
        PREFIX ${CMAKE_BINARY_DIR}/components_rs
        SOURCE_DIR ${CMAKE_SOURCE_DIR}/../components-rs
        CONFIGURE_COMMAND ""
        BUILD_COMMAND sh -c ${CARGO_BUILD_ENV}\ ${CARGO_BUILD_CMD}\ --target-dir=${CMAKE_BINARY_DIR}/components_rs
        INSTALL_COMMAND ""
        DEPENDS libdatadog_stamp
        BUILD_IN_SOURCE TRUE
    )

    add_library(components_rs STATIC IMPORTED)
    if(CMAKE_BUILD_TYPE STREQUAL "Debug")
        set(CARGO_BUILD_LOCATION ${CMAKE_BINARY_DIR}/components_rs/debug)
    else()
        set(CARGO_BUILD_LOCATION ${CMAKE_BINARY_DIR}/components_rs/release)
    endif()
    set_property(TARGET components_rs PROPERTY IMPORTED_LOCATION
        ${CARGO_BUILD_LOCATION}/libdatadog_php.a)
    add_dependencies(components_rs components_rs_proj)

    if(DD_APPSEC_SSI)
        add_library(ddtrace_php INTERFACE IMPORTED GLOBAL)
        set_target_properties(ddtrace_php PROPERTIES
            IMPORTED_LIBNAME ddtrace_php)
        add_dependencies(ddtrace_php components_rs_proj)
    endif()
endif()


execute_process(
    COMMAND ${PhpConfig_EXECUTABLE} --vernum
    RESULT_VARIABLE PhpConfig_VERNUM_RESULT
    OUTPUT_VARIABLE PhpConfig_VERNUM
    OUTPUT_STRIP_TRAILING_WHITESPACE COMMAND_ERROR_IS_FATAL ANY)

file(GLOB_RECURSE FILES_DDTRACE
    CONFIGURE_DEPENDS
    "${CMAKE_SOURCE_DIR}/../ext/*.c"
    "${CMAKE_SOURCE_DIR}/../ext/**/*.c"
    "${CMAKE_SOURCE_DIR}/../tracer/*.c"
    "${CMAKE_SOURCE_DIR}/../tracer/**/*.c"
    "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/*.c"
    "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/**/*.c"
)

list(APPEND FILES_DDTRACE
    "${CMAKE_SOURCE_DIR}/../src/dogstatsd/client.c"
    "${CMAKE_SOURCE_DIR}/../components/log/log.c"
    "${CMAKE_SOURCE_DIR}/../components/sapi/sapi.c"
    "${CMAKE_SOURCE_DIR}/../components/string_view/string_view.c"
    "${CMAKE_SOURCE_DIR}/../tracer/vendor/mpack/mpack.c"
    "${CMAKE_SOURCE_DIR}/../tracer/vendor/mt19937/mt19937-64.c"
)
if (PhpConfig_VERNUM GREATER_EQUAL 80000)
    list(REMOVE_ITEM FILES_DDTRACE "${CMAKE_SOURCE_DIR}/../tracer/handlers_curl_php7.c"
        "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/interceptor/php7/interceptor.c"
        "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/interceptor/php7/resolver.c"
        "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/sandbox/php7/sandbox.c")
else() # PHP 7
    list(REMOVE_ITEM FILES_DDTRACE "${CMAKE_SOURCE_DIR}/../tracer/handlers_curl.c"
        "${CMAKE_SOURCE_DIR}/../tracer/hook/uhook_attributes.c"
        "${CMAKE_SOURCE_DIR}/../tracer/hook/uhook_otel.c"
        "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/interceptor/php8/interceptor.c"
        "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/interceptor/php8/resolver.c"
        "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/interceptor/php8/resolver_pre-8_2.c"
        "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/jit_utils/jit_blacklist.c"
        "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/sandbox/php8/sandbox.c")
endif()
if (PhpConfig_VERNUM GREATER_EQUAL 70300)
    list(REMOVE_ITEM FILES_DDTRACE "${CMAKE_SOURCE_DIR}/../ext/zend_hrtime.c")
endif()
if (PhpConfig_VERNUM LESS 80000 OR PhpConfig_VERNUM GREATER_EQUAL 80200)
    list(REMOVE_ITEM FILES_DDTRACE "${CMAKE_SOURCE_DIR}/../ext/patch_zend_call_known_function.c")
endif()
if (PhpConfig_VERNUM LESS 80200)
    list(REMOVE_ITEM FILES_DDTRACE "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/interceptor/php8/resolver.c")
else() # PHP 8.2+
    list(REMOVE_ITEM FILES_DDTRACE "${CMAKE_SOURCE_DIR}/../tracer/weakrefs.c"
        "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/interceptor/php8/resolver_pre-8_2.c")
endif()
if (PhpConfig_VERNUM LESS 80100)
    list(REMOVE_ITEM FILES_DDTRACE "${CMAKE_SOURCE_DIR}/../tracer/handlers_fiber.c")
endif()
list(REMOVE_ITEM FILES_DDTRACE "${CMAKE_SOURCE_DIR}/../ext/crashtracking_windows.c")
if(NOT CMAKE_SYSTEM_NAME STREQUAL "Linux")
    list(REMOVE_ITEM FILES_DDTRACE "${CMAKE_SOURCE_DIR}/../tracer/otel_context.c")
endif()

find_package(CURL REQUIRED)
message(STATUS "CURL version: ${CURL_VERSION_STRING}")

include(cmake/pcre2.cmake)

add_library(ddtrace_objects OBJECT ${FILES_DDTRACE})
set_target_properties(ddtrace_objects PROPERTIES
    C_VISIBILITY_PRESET hidden
    POSITION_INDEPENDENT_CODE ON)
target_compile_options(ddtrace_objects PRIVATE
    -fms-extensions -Wno-microsoft-anon-tag)
if(${CMAKE_SYSTEM_NAME} STREQUAL "Linux")
    target_compile_definitions(ddtrace_objects PRIVATE _GNU_SOURCE)
    if(CMAKE_SYSTEM_PROCESSOR MATCHES "^(x86_64|amd64|AMD64)$")
        include(CheckCCompilerFlag)
        check_c_compiler_flag("-mtls-dialect=gnu2" COMPILER_HAS_GNU2_TLS_DIALECT)
        if(NOT COMPILER_HAS_GNU2_TLS_DIALECT)
            message(FATAL_ERROR "x86-64 Linux OTel context sharing requires compiler support for -mtls-dialect=gnu2")
        endif()
        target_compile_options(ddtrace_objects PRIVATE -mtls-dialect=gnu2)
    endif()
endif()
target_compile_definitions(ddtrace_objects PRIVATE
    ZEND_ENABLE_STATIC_TSRMLS_CACHE=1 COMPILE_DL_DDTRACE=1 DDTRACE=1)
target_include_directories(ddtrace_objects PRIVATE
    ${CURL_INCLUDE_DIRS}
    ${CMAKE_SOURCE_DIR}/..
    ${CMAKE_SOURCE_DIR}/../src/dogstatsd
    ${CMAKE_SOURCE_DIR}/../zend_abstract_interface
    ${CMAKE_SOURCE_DIR}/../ext
    ${CMAKE_SOURCE_DIR}/../tracer
    ${CMAKE_SOURCE_DIR}/../tracer/integrations
    ${CMAKE_SOURCE_DIR}/../tracer/vendor
    ${CMAKE_SOURCE_DIR}/../tracer/vendor/mpack
    ${CMAKE_SOURCE_DIR}/../tracer/vendor/mt19937
    ${CMAKE_BINARY_DIR}/gen_ddtrace
    ${CMAKE_BINARY_DIR}/gen_ddtrace/ext
)
target_link_libraries(ddtrace_objects PRIVATE
    PhpConfig ${CURL_LIBRARIES} PCRE2::pcre2)
if(CURL_DEFINITIONS)
    target_compile_definitions(ddtrace_objects PRIVATE ${CURL_DEFINITIONS})
endif()
add_dependencies(ddtrace_objects update_version_h)

if(DD_APPSEC_PHP_SIDECAR_MOCKGEN)
    if(NOT EXISTS "${DD_APPSEC_PHP_SIDECAR_MOCKGEN}")
        message(FATAL_ERROR
            "Prebuilt php_sidecar_mockgen does not exist: ${DD_APPSEC_PHP_SIDECAR_MOCKGEN}")
    endif()
    add_custom_target(ddtrace_weaken_php_symbols
        COMMAND "${DD_APPSEC_PHP_SIDECAR_MOCKGEN}" weaken-dynsym
            $<TARGET_OBJECTS:ddtrace_objects> "${PhpConfig_PHP_BINARY}"
        DEPENDS ddtrace_objects
        COMMAND_EXPAND_LISTS
        VERBATIM)
endif()

add_library(ddtrace SHARED $<TARGET_OBJECTS:ddtrace_objects>)
set_target_properties(ddtrace PROPERTIES
    OUTPUT_NAME ddtrace
    DEBUG_POSTFIX ""
    PREFIX "")
if(${CMAKE_SYSTEM_NAME} STREQUAL "Linux")
    target_link_options(ddtrace PRIVATE "-Wl,--version-script=${EXPORTS_FILE}")
    if(NOT DD_APPSEC_SSI)
        target_link_options(ddtrace PRIVATE
            "-Wl,-e,ddog_spawn_direct_entry")
    endif()
elseif(APPLE)
    target_link_options(ddtrace PRIVATE "-exported_symbols_list" "${EXPORTS_FILE}")
else()
    message(FATAL_ERROR "Only Linux and Apple supported")
endif()
if(DD_APPSEC_SSI)
    if(NOT DD_APPSEC_LIBDATADOG_PHP)
        target_link_directories(ddtrace PRIVATE ${CARGO_BUILD_LOCATION})
    endif()
    target_link_libraries(ddtrace PRIVATE PhpConfig ddtrace_php ${CURL_LIBRARIES} PCRE2::pcre2)
    if(APPLE)
        set_target_properties(ddtrace PROPERTIES BUILD_RPATH "@loader_path")
    else()
        set_target_properties(ddtrace PROPERTIES BUILD_RPATH "\$ORIGIN" INSTALL_RPATH "\$ORIGIN")
    endif()
else()
    target_link_libraries(ddtrace PRIVATE PhpConfig components_rs ${CURL_LIBRARIES} PCRE2::pcre2)
endif()
if(DD_APPSEC_PHP_SIDECAR_MOCKGEN)
    add_dependencies(ddtrace ddtrace_weaken_php_symbols)
endif()
add_dependencies(ddtrace ddtrace_exports)

patch_away_libc(ddtrace)
