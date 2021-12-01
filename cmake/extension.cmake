message(STATUS "Project version is ${CMAKE_PROJECT_VERSION}")
configure_file(src/extension/version.h.in ${CMAKE_CURRENT_SOURCE_DIR}/src/extension/version.h)

find_program(PHP_CONFIG php-config)
if(PHP_CONFIG STREQUAL PHP_CONFIG-NOTFOUND)
    message(FATAL_ERROR "Cannot find php-config, either set PHP_CONFIG or make it discoverable")
endif()

set(EXT_SOURCE_DIR src/extension)
file(GLOB_RECURSE EXT_SOURCE ${EXT_SOURCE_DIR}/*.c)
add_library(extension SHARED ${EXT_SOURCE})
set_target_properties(extension PROPERTIES
    C_VISIBILITY_PRESET hidden
    OUTPUT_NAME ddappsec
    DEBUG_POSTFIX ""
    PREFIX "")
target_compile_definitions(extension PRIVATE TESTING=1 ZEND_ENABLE_STATIC_TSRMLS_CACHE=1)
target_link_libraries(extension PRIVATE mpack)

macro(target_linker_flag_conditional target) # flags as argv
    try_compile(LINKER_HAS_FLAG "${CMAKE_CURRENT_BINARY_DIR}" "${CMAKE_CURRENT_SOURCE_DIR}/cmake/check.c"
        LINK_OPTIONS ${ARGN}
        OUTPUT_VARIABLE LINKER_HAS_FLAG_ERROR_LOG)

    if(LINKER_HAS_FLAG)
        target_link_options(${target} PRIVATE ${ARGN})
        message(STATUS "Linker has flag ${ARGN}")
    else()
        #message(STATUS "Linker does not have flag: ${LINKER_HAS_FLAG_ERROR_LOG}")
    endif()
endmacro()

if(NOT MSVC)
    # we don't have any C++ now, but just so we don't forget in the future...
    check_cxx_compiler_flag("-fno-gnu-unique" COMPILER_HAS_NO_GNU_UNIQUE)
    if(COMPILER_HAS_NO_GNU_UNIQUE)
        target_compile_options(extension PRIVATE $<$<COMPILE_LANGUAGE:CXX>:-fno-gnu-unique>)
    endif()
    target_compile_options(extension PRIVATE $<$<COMPILE_LANGUAGE:CXX>:-fno-rtti -fno-exceptions>)
    target_compile_options(extension PRIVATE -Wall -Wextra -Wno-unused-parameter -Werror)
    # our thread local variables are only used by ourselves
    target_compile_options(extension PRIVATE -ftls-model=local-dynamic)

    target_linker_flag_conditional(extension -Wl,--as-needed)
    # ld doesn't necessarily respect the visibility of hidden symbols if
    # they're inside static libraries, so use a linker script only exporting
    # ddappsec.version as a safeguard
    target_linker_flag_conditional(extension "-Wl,--version-script=${CMAKE_CURRENT_SOURCE_DIR}/ddappsec.version")

    # Mac OS
    target_linker_flag_conditional(extension -flat_namespace -undefined suppress)
    target_linker_flag_conditional(extension -Wl,-exported_symbol -Wl,_get_module)
endif()

# PHP includes
execute_process(
    COMMAND ${PHP_CONFIG} --includes
    RESULT_VARIABLE PHP_CONFIG_INCLUDES_RESULT
    OUTPUT_VARIABLE PHP_CONFIG_INCLUDES
    ERROR_VARIABLE PHP_CONFIG_INCLUDES_ERR
    OUTPUT_STRIP_TRAILING_WHITESPACE
    )

if("${PHP_CONFIG_INCLUDES_RESULT}" STREQUAL "0")
    string(REPLACE "-I " "-I" PHP_INCLUDES "${PHP_CONFIG_INCLUDES}")
    string(REPLACE "-I" "-isystem" PHP_INCLUDES "${PHP_INCLUDES}")
    string(REPLACE " " ";" PHP_INCLUDES "${PHP_INCLUDES}")
    message(STATUS "Using PHP include flags: ${PHP_INCLUDES}")
else()
    message(FATAL_ERROR "Error executing ${PHP_CONFIG} --includes: ${PHP_CONFIG_INCLUDES_ERR}")
endif()

target_compile_options(extension PRIVATE ${PHP_INCLUDES})

# PHP LDFLAGS
execute_process(
    COMMAND ${PHP_CONFIG} --ldflags
    RESULT_VARIABLE PHP_CONFIG_LDFLAGS_RESULT
    OUTPUT_VARIABLE PHP_CONFIG_LDFLAGS
    ERROR_VARIABLE PHP_CONFIG_LDFLAGS_ERR
    OUTPUT_STRIP_TRAILING_WHITESPACE
    )

if("${PHP_CONFIG_LDFLAGS_RESULT}" STREQUAL "0")
    string(REPLACE " " ";" PHP_CONFIG_LDFLAGS "${PHP_CONFIG_LDFLAGS}")
    message(STATUS "Using PHP linker flags: ${PHP_CONFIG_LDFLAGS}")
else()
    message(FATAL_ERROR "Error executing ${PHP_CONFIG} --ldflags: ${PHP_CONFIG_LDFLAGS_ERR}")
endif()

target_link_options(extension PRIVATE ${PHP_CONFIG_LDFLAGS})

if(DD_APPSEC_ENABLE_COVERAGE)
    target_compile_options(extension PRIVATE --coverage)
    target_link_options(extension PRIVATE --coverage)
endif()

patch_away_libc(extension)

include(cmake/run_tests.cmake)
include(cmake/extension_api.cmake)

# Installation
find_extension_api(ZEND_EXT_API)
message(STATUS "Zend API spec: ${ZEND_EXT_API}")

install(TARGETS extension DESTINATION ${CMAKE_INSTALL_LIBDIR}/php/${ZEND_EXT_API})
split_debug(extension ${CMAKE_INSTALL_LIBDIR}/php/${ZEND_EXT_API})

# vim set: et:
