configure_file(src/extension/version.h.in ${CMAKE_CURRENT_SOURCE_DIR}/src/extension/version.h)

find_package(PhpConfig REQUIRED)

set(EXT_SOURCE_DIR src/extension)
file(GLOB_RECURSE EXT_SOURCE ${EXT_SOURCE_DIR}/*.c)
file(GLOB_RECURSE ZAI_SOURCE ../zend_abstract_interface/config/*.c
 ../zend_abstract_interface/json/*.c ../zend_abstract_interface/env/*.c
 ../zend_abstract_interface/zai_string/*.c)

add_library(extension SHARED ${EXT_SOURCE} ${ZAI_SOURCE})
set_target_properties(extension PROPERTIES
    C_VISIBILITY_PRESET hidden
    OUTPUT_NAME ddappsec
    DEBUG_POSTFIX ""
    PREFIX "")
target_compile_definitions(extension PRIVATE TESTING=1 ZEND_ENABLE_STATIC_TSRMLS_CACHE=1)

target_link_libraries(extension PRIVATE mpack PhpConfig)

target_include_directories(extension PRIVATE ../zend_abstract_interface)

if(DD_APPSEC_ENABLE_COVERAGE)
    target_compile_options(extension PRIVATE --coverage)
    target_link_options(extension PRIVATE --coverage)
endif()

include(cmake/run_tests.cmake)
