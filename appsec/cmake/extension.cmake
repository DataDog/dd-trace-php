configure_file(src/extension/version.h.in ${CMAKE_CURRENT_SOURCE_DIR}/src/extension/version.h)

include(cmake/libxml2.cmake)

set(EXT_SOURCE_DIR src/extension)

# Create controlled include directory with symlinks to avoid accidentally including
# unrelated files from the root directory
set(EXT_ROOT_INCLUDES ${CMAKE_BINARY_DIR}/ext_root_includes)
file(MAKE_DIRECTORY ${EXT_ROOT_INCLUDES})
file(CREATE_LINK ${CMAKE_CURRENT_SOURCE_DIR}/../zend_abstract_interface
    ${EXT_ROOT_INCLUDES}/zend_abstract_interface SYMBOLIC)
file(CREATE_LINK ${CMAKE_CURRENT_SOURCE_DIR}/../components-rs
    ${EXT_ROOT_INCLUDES}/components-rs SYMBOLIC)

file(GLOB_RECURSE ZAI_SOURCE ../zend_abstract_interface/config/*.c
 ../zend_abstract_interface/json/*.c ../zend_abstract_interface/env/*.c
 ../zend_abstract_interface/zai_string/*.c)

add_library(zai STATIC ${ZAI_SOURCE})

target_link_libraries(zai PRIVATE PhpConfig)
target_include_directories(zai PUBLIC ../zend_abstract_interface ${EXT_ROOT_INCLUDES})
set_target_properties(zai PROPERTIES POSITION_INDEPENDENT_CODE 1)

include(cmake/pcre2.cmake)

file(GLOB_RECURSE EXT_SOURCE ${EXT_SOURCE_DIR}/*.c ${EXT_SOURCE_DIR}/*.cpp)
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

target_link_libraries(extension PRIVATE mpack PhpConfig zai rapidjson_appsec libxml2_static PCRE2::pcre2)
target_include_directories(extension PRIVATE ${EXT_ROOT_INCLUDES})

# gnu unique prevents shared libraries from being unloaded from memory by dlclose
check_cxx_compiler_flag("-fno-gnu-unique" COMPILER_HAS_NO_GNU_UNIQUE)
if(COMPILER_HAS_NO_GNU_UNIQUE)
target_compile_options(extension PRIVATE $<$<COMPILE_LANGUAGE:CXX>:-fno-gnu-unique>)
endif()
target_compile_options(extension PRIVATE $<$<COMPILE_LANGUAGE:CXX>:-std=c++17 -fno-rtti -fno-exceptions>)
if(CMAKE_CXX_COMPILER_ID STREQUAL "GNU" AND CMAKE_CXX_COMPILER_VERSION VERSION_LESS 13)
  target_compile_options(extension PRIVATE -Wall)
else()
  target_compile_options(extension PRIVATE -Wall -Wextra $<$<COMPILE_LANGUAGE:C>:-pedantic>
    -Werror -Wno-nullability-extension -Wno-gnu-zero-variadic-macro-arguments
    -Wno-gnu-auto-type -Wno-language-extension-token
    $<$<COMPILE_LANGUAGE:CXX>:-Wno-missing-field-initializers>)
endif()
# our thread local variables are only used by ourselves
target_compile_options(extension PRIVATE -ftls-model=local-dynamic)

include(cmake/cond_flag.cmake)

target_linker_flag_conditional(extension -Wl,--as-needed)
# ld doesn't necessarily respect the visibility of hidden symbols if
# they're inside static libraries, so use a linker script only exporting
# symbols listed in ddappsec.version as a safeguard
# Test with --version-script support first (not the actual file which references undefined symbols)
include(CheckLinkerFlag)
check_linker_flag(C "-Wl,--version-script=${CMAKE_CURRENT_SOURCE_DIR}/cmake/check_version_script.version" LINKER_SUPPORTS_VERSION_SCRIPT)
if(LINKER_SUPPORTS_VERSION_SCRIPT)
    target_link_options(extension PRIVATE "-Wl,--version-script=${CMAKE_CURRENT_SOURCE_DIR}/src/extension/ddappsec.version")
    message(STATUS "Linker has flag -Wl,--version-script")
endif()

# Mac OS
target_linker_flag_conditional(extension -flat_namespace "-undefined suppress")
target_linker_flag_conditional(extension -Wl,-exported_symbol -Wl,_get_module)

if(DD_APPSEC_EXTENSION_STATIC_LIBSTDCXX AND NOT APPLE)
    target_link_options(extension PRIVATE -static-libstdc++)
endif()

patch_away_libc(extension)

if(DD_APPSEC_TESTING)
    maybe_enable_coverage(extension)

    include(cmake/run_tests.cmake)
endif()
