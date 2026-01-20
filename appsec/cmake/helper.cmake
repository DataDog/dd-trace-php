configure_file(src/helper/version.hpp.in ${CMAKE_CURRENT_BINARY_DIR}/src/helper/version.hpp)

set(HELPER_SOURCE_DIR src/helper)
set(HELPER_INCLUDE_DIR ${CMAKE_CURRENT_SOURCE_DIR}/src/helper)
set(HELPER_BUILD_INCLUDE_DIR ${CMAKE_CURRENT_BINARY_DIR}/src/helper)

file(GLOB_RECURSE HELPER_SOURCE CONFIGURE_DEPENDS
    ${HELPER_SOURCE_DIR}/*.cpp ${HELPER_SOURCE_DIR}/*.c)
list(FILTER HELPER_SOURCE EXCLUDE REGEX "^.*main\.cpp$")

add_library(helper_objects OBJECT ${HELPER_SOURCE})
set_target_properties(helper_objects PROPERTIES
    CXX_VISIBILITY_PRESET hidden
    CXX_STANDARD 20
    CXX_STANDARD_REQUIRED YES
    POSITION_INDEPENDENT_CODE 1)
target_include_directories(helper_objects
    INTERFACE ${HELPER_INCLUDE_DIR}
    PUBLIC ${HELPER_BUILD_INCLUDE_DIR}
    PUBLIC ${CMAKE_CURRENT_SOURCE_DIR}/../components-rs
)
target_compile_definitions(helper_objects PUBLIC SPDLOG_ACTIVE_LEVEL=SPDLOG_LEVEL_TRACE)
if(CMAKE_CXX_COMPILER_ID STREQUAL "GNU" AND CMAKE_CXX_COMPILER_VERSION VERSION_LESS 13)
  target_compile_options(helper_objects PRIVATE -Wall)
else()
  target_compile_options(helper_objects PRIVATE -Wall -Wextra -pedantic -Werror)
endif()
target_compile_options(helper_objects PRIVATE -ftls-model=global-dynamic)
target_link_libraries(helper_objects PUBLIC libddwaf_objects pthread spdlog
    cpp-base64 msgpack_c rapidjson_appsec boost_system zlibstatic)

target_compile_options(helper_objects PRIVATE -Wno-gnu-anonymous-struct -Wno-nested-anon-types)

add_library(ddappsec-helper SHARED
    src/helper/main.cpp
    $<TARGET_OBJECTS:helper_objects>
    $<TARGET_OBJECTS:libddwaf_objects>)
target_link_libraries(ddappsec-helper helper_objects) # for its PUBLIC deps
if(CMAKE_SYSTEM_NAME STREQUAL "Linux")
    target_compile_options(ddappsec-helper PRIVATE -ftls-model=global-dynamic)
    # Bind symbols lookup of symbols defined in the library to the library itself
    # also avoids relocation problems with libc++.a on linux/aarch64
    target_link_options(ddappsec-helper PRIVATE -Wl,-Bsymbolic)
elseif(CMAKE_SYSTEM_NAME STREQUAL "Darwin")
    target_link_options(ddappsec-helper PRIVATE -undefined dynamic_lookup)
endif()
set_target_properties(ddappsec-helper PROPERTIES
    CXX_VISIBILITY_PRESET hidden
    CXX_STANDARD 20
    CXX_STANDARD_REQUIRED YES
    POSITION_INDEPENDENT_CODE 1
    DEBUG_POSTFIX ""
    SUFFIX .so
)

include(cmake/cond_flag.cmake)
target_linker_flag_conditional(ddappsec-helper "-Wl,--version-script=${CMAKE_CURRENT_SOURCE_DIR}/src/helper/helper.version")

patch_away_libc(ddappsec-helper)

try_compile(STDLIBXX_FS_NO_LIB_NEEDED ${CMAKE_CURRENT_BINARY_DIR}
    SOURCES ${CMAKE_CURRENT_SOURCE_DIR}/cmake/check_fslib.cpp
    CXX_STANDARD 20
    CXX_STANDARD_REQUIRED TRUE)
try_compile(STDLIBXX_FS_NEEDS_STDCXXFS ${CMAKE_CURRENT_BINARY_DIR}

    SOURCES ${CMAKE_CURRENT_SOURCE_DIR}/cmake/check_fslib.cpp
    CXX_STANDARD 20
    CXX_STANDARD_REQUIRED TRUE
    LINK_LIBRARIES stdc++fs)
try_compile(STDLIBXX_FS_NEEDS_CXXFS ${CMAKE_CURRENT_BINARY_DIR}
    SOURCES ${CMAKE_CURRENT_SOURCE_DIR}/cmake/check_fslib.cpp
    CXX_STANDARD 20
    CXX_STANDARD_REQUIRED TRUE
    LINK_LIBRARIES c++fs)
if(NOT STDLIBXX_FS_NO_LIB_NEEDED)
    if(STDLIBXX_FS_NEEDS_STDCXXFS)
        target_link_libraries(helper_objects PUBLIC stdc++fs)
    elseif(STDLIBXX_FS_NEEDS_CXXFS)
        target_link_libraries(helper_objects PUBLIC c++fs)
    else()
        message(FATAL_ERROR "Could not compile a program using std::filesystem")
    endif()
endif()

if(DD_APPSEC_TESTING)
       # Testing and examples
       add_subdirectory(tests/helper EXCLUDE_FROM_ALL)
       add_subdirectory(tests/bench EXCLUDE_FROM_ALL)
       add_subdirectory(tests/fuzzer EXCLUDE_FROM_ALL)

       if(DD_APPSEC_ENABLE_COVERAGE)
           maybe_enable_coverage(helper_objects)
           maybe_enable_coverage(ddappsec_helper_test)

           # helper objects are shared, so we need to link ddappsec-helper with coverage too
           maybe_enable_coverage(ddappsec-helper)
       endif()
endif()
