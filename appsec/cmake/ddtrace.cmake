include(cmake/components_rs.cmake)

execute_process(
    COMMAND ${PhpConfig_EXECUTABLE} --vernum
    RESULT_VARIABLE PhpConfig_VERNUM_RESULT
    OUTPUT_VARIABLE PhpConfig_VERNUM
    OUTPUT_STRIP_TRAILING_WHITESPACE COMMAND_ERROR_IS_FATAL ANY)

file(GLOB_RECURSE FILES_DDTRACE
    CONFIGURE_DEPENDS
    "${CMAKE_SOURCE_DIR}/../ext/*.c"
    "${CMAKE_SOURCE_DIR}/../ext/**/*.c"
    "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/*.c"
    "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/**/*.c"
)

list(APPEND FILES_DDTRACE
    "${CMAKE_SOURCE_DIR}/../src/dogstatsd/client.c"
    "${CMAKE_SOURCE_DIR}/../components/container_id/container_id.c"
    "${CMAKE_SOURCE_DIR}/../components/log/log.c"
    "${CMAKE_SOURCE_DIR}/../components/sapi/sapi.c"
    "${CMAKE_SOURCE_DIR}/../components/string_view/string_view.c"
)
if (PhpConfig_VERNUM GREATER_EQUAL 80000)
    list(REMOVE_ITEM FILES_DDTRACE "${CMAKE_SOURCE_DIR}/../ext/handlers_curl_php7.c"
        "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/interceptor/php7/interceptor.c"
        "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/interceptor/php7/resolver.c"
        "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/sandbox/php7/sandbox.c")
else() # PHP 7
    list(REMOVE_ITEM FILES_DDTRACE "${CMAKE_SOURCE_DIR}/../ext/handlers_curl.c"
        "${CMAKE_SOURCE_DIR}/../ext/hook/uhook_attributes.c"
        "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/interceptor/php8/interceptor.c"
        "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/interceptor/php8/resolver.c"
        "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/interceptor/php8/resolver_pre-8_2.c"
        "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/jit_utils/jit_blacklist.c"
        "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/sandbox/php8/sandbox.c")
endif()
if (PhpConfig_VERNUM LESS 80200)
    list(REMOVE_ITEM FILES_DDTRACE "${CMAKE_SOURCE_DIR}/../ext/weakrefs.c")
    list(REMOVE_ITEM FILES_DDTRACE "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/interceptor/php8/resolver.c")
else() # PHP 8.2+
    list(REMOVE_ITEM FILES_DDTRACE "${CMAKE_SOURCE_DIR}/../zend_abstract_interface/interceptor/php8/resolver_pre-8_2.c")
endif()
if (PhpConfig_VERNUM LESS 80100)
    list(REMOVE_ITEM FILES_DDTRACE "${CMAKE_SOURCE_DIR}/../ext/handlers_fiber.c")
endif()

find_package(CURL REQUIRED)

add_library(ddtrace SHARED ${FILES_DDTRACE})
set_target_properties(ddtrace PROPERTIES
    C_VISIBILITY_PRESET hidden
    OUTPUT_NAME ddtrace
    DEBUG_POSTFIX ""
    PREFIX "")
target_compile_options(ddtrace PRIVATE -fms-extensions -Wno-microsoft-anon-tag)
if(${CMAKE_SYSTEM_NAME} STREQUAL "Linux")
    target_compile_definitions(ddtrace PRIVATE _GNU_SOURCE)
    target_link_options(ddtrace PRIVATE "-Wl,--version-script=${EXPORTS_FILE}")
elseif(APPLE)
    target_link_options(ddtrace PRIVATE "-exported_symbols_list" "${EXPORTS_FILE}")
else()
    message(FATAL_ERROR "Only Linux and Apple supported")
endif()
target_link_libraries(ddtrace PRIVATE PhpConfig components_rs ${CURL_LIBRARIES})
if(CURL_DEFINITIONS)
    target_compile_definitions(ddtrace PRIVATE ${CURL_DEFINITIONS})
endif()
target_compile_definitions(ddtrace PRIVATE ZEND_ENABLE_STATIC_TSRMLS_CACHE=1 COMPILE_DL_DDTRACE=1)
target_include_directories(ddtrace PRIVATE
    ${CURL_INCLUDE_DIRS}
    ${CMAKE_SOURCE_DIR}/..
    ${CMAKE_SOURCE_DIR}/../src/dogstatsd
    ${CMAKE_SOURCE_DIR}/../zend_abstract_interface
    ${CMAKE_SOURCE_DIR}/../ext
    ${CMAKE_SOURCE_DIR}/../ext/vendor
    ${CMAKE_SOURCE_DIR}/../ext/vendor/mt19937
    ${CMAKE_BINARY_DIR}/gen_ddtrace
)
add_dependencies(ddtrace ddtrace_exports update_version_h)

patch_away_libc(ddtrace)
