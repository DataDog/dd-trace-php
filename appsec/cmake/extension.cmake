configure_file(src/extension/version.h.in ${CMAKE_CURRENT_SOURCE_DIR}/src/extension/version.h)

set(EXT_SOURCE_DIR src/extension)

file(GLOB_RECURSE ZAI_SOURCE ../zend_abstract_interface/config/*.c
 ../zend_abstract_interface/json/*.c ../zend_abstract_interface/env/*.c
 ../zend_abstract_interface/zai_string/*.c)

add_library(zai STATIC ${ZAI_SOURCE})

target_link_libraries(zai PRIVATE PhpConfig)
target_include_directories(zai PUBLIC ../zend_abstract_interface ..)
set_target_properties(zai PROPERTIES POSITION_INDEPENDENT_CODE 1)

file(GLOB_RECURSE EXT_SOURCE ${EXT_SOURCE_DIR}/*.c)
add_library(extension SHARED ${EXT_SOURCE})
set_target_properties(extension PROPERTIES
    C_VISIBILITY_PRESET hidden
    OUTPUT_NAME ddappsec
    DEBUG_POSTFIX ""
    PREFIX "")
target_compile_definitions(extension PRIVATE TESTING=1 ZEND_ENABLE_STATIC_TSRMLS_CACHE=1 -D_GNU_SOURCE)

# link against zai, but make their includes system includes to avoid warnings
get_target_property(ZAI_INCLUDE_DIRS zai INTERFACE_INCLUDE_DIRECTORIES)
set_target_properties(extension PROPERTIES INTERFACE_INCLUDE_DIRECTORIES "")
target_include_directories(extension SYSTEM PRIVATE ${ZAI_INCLUDE_DIRS})
if(ZAI_INCLUDE_DIRS)
  target_include_directories(extension SYSTEM PRIVATE ${ZAI_INCLUDE_DIRS})
endif()
target_link_libraries(extension PRIVATE zai)

target_link_libraries(extension PRIVATE mpack PhpConfig zai)
target_include_directories(extension PRIVATE ..)

# we don't have any C++ now, but just so we don't forget in the future...
check_cxx_compiler_flag("-fno-gnu-unique" COMPILER_HAS_NO_GNU_UNIQUE)
if(COMPILER_HAS_NO_GNU_UNIQUE)
target_compile_options(extension PRIVATE $<$<COMPILE_LANGUAGE:CXX>:-fno-gnu-unique>)
endif()
target_compile_options(extension PRIVATE $<$<COMPILE_LANGUAGE:CXX>:-fno-rtti -fno-exceptions>)
if(CMAKE_CXX_COMPILER_ID STREQUAL "GNU" AND CMAKE_CXX_COMPILER_VERSION VERSION_LESS 13)
  target_compile_options(extension PRIVATE -Wall)
else()
  target_compile_options(extension PRIVATE -Wall -Wextra -pedantic -Werror -Wno-nullability-extension
    -Wno-gnu-zero-variadic-macro-arguments -Wno-gnu-auto-type -Wno-language-extension-token)
endif()
# our thread local variables are only used by ourselves
target_compile_options(extension PRIVATE -ftls-model=local-dynamic)

include(cmake/cond_flag.cmake)

target_linker_flag_conditional(extension -Wl,--as-needed)
# ld doesn't necessarily respect the visibility of hidden symbols if
# they're inside static libraries, so use a linker script only exporting
# ddappsec.version as a safeguard
target_linker_flag_conditional(extension "-Wl,--version-script=${CMAKE_CURRENT_SOURCE_DIR}/src/extension/ddappsec.version")

# Mac OS
target_linker_flag_conditional(extension -flat_namespace "-undefined suppress")
target_linker_flag_conditional(extension -Wl,-exported_symbol -Wl,_get_module)

patch_away_libc(extension)

if(DD_APPSEC_TESTING)
    maybe_enable_coverage(extension)

    include(cmake/run_tests.cmake)
endif()
