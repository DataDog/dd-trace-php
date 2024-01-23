configure_file(src/extension/version.h.in ${CMAKE_CURRENT_SOURCE_DIR}/src/extension/version.h)

find_package(PhpConfig REQUIRED)

set(EXT_SOURCE_DIR src/extension)

file(GLOB_RECURSE ZAI_SOURCE ../zend_abstract_interface/config/*.c
 ../zend_abstract_interface/json/*.c ../zend_abstract_interface/env/*.c
 ../zend_abstract_interface/zai_string/*.c)

add_library(zai STATIC ${ZAI_SOURCE})
target_link_libraries(zai PRIVATE PhpConfig)
target_include_directories(zai PUBLIC ../zend_abstract_interface)
set_target_properties(zai PROPERTIES POSITION_INDEPENDENT_CODE 1)

file(GLOB_RECURSE EXT_SOURCE ${EXT_SOURCE_DIR}/*.c)
add_library(extension SHARED ${EXT_SOURCE})
set_target_properties(extension PROPERTIES
    C_VISIBILITY_PRESET hidden
    OUTPUT_NAME ddappsec
    DEBUG_POSTFIX ""
    PREFIX "")
target_compile_definitions(extension PRIVATE TESTING=1 ZEND_ENABLE_STATIC_TSRMLS_CACHE=1 -D_GNU_SOURCE)

target_link_libraries(extension PRIVATE mpack PhpConfig zai)

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

# we don't have any C++ now, but just so we don't forget in the future...
check_cxx_compiler_flag("-fno-gnu-unique" COMPILER_HAS_NO_GNU_UNIQUE)
if(COMPILER_HAS_NO_GNU_UNIQUE)
target_compile_options(extension PRIVATE $<$<COMPILE_LANGUAGE:CXX>:-fno-gnu-unique>)
endif()
target_compile_options(extension PRIVATE $<$<COMPILE_LANGUAGE:CXX>:-fno-rtti -fno-exceptions>)
target_compile_options(extension PRIVATE -Wall -Wextra -Werror)
# our thread local variables are only used by ourselves
target_compile_options(extension PRIVATE -ftls-model=local-dynamic)

target_linker_flag_conditional(extension -Wl,--as-needed)
# ld doesn't necessarily respect the visibility of hidden symbols if
# they're inside static libraries, so use a linker script only exporting
# ddappsec.version as a safeguard
target_linker_flag_conditional(extension "-Wl,--version-script=${CMAKE_CURRENT_SOURCE_DIR}/ddappsec.version")

# Mac OS
target_linker_flag_conditional(extension -flat_namespace "-undefined suppress")
target_linker_flag_conditional(extension -Wl,-exported_symbol -Wl,_get_module)

patch_away_libc(extension)

if(DD_APPSEC_TESTING)
    if(DD_APPSEC_ENABLE_COVERAGE)
        target_compile_options(extension PRIVATE --coverage)
        target_link_options(extension PRIVATE --coverage)
    endif()

    include(cmake/run_tests.cmake)
endif()
