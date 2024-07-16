hunter_add_package(Boost COMPONENTS system)
find_package(Boost CONFIG REQUIRED COMPONENTS system)

hunter_add_package(RapidJSON)
find_package(RapidJSON CONFIG REQUIRED)
set_target_properties(RapidJSON::rapidjson PROPERTIES INTERFACE_COMPILE_DEFINITIONS "RAPIDJSON_HAS_STDSTRING=1")

configure_file(src/helper/version.hpp.in ${CMAKE_CURRENT_SOURCE_DIR}/src/helper/version.hpp)

set(HELPER_SOURCE_DIR src/helper)
set(HELPER_INCLUDE_DIR ${CMAKE_CURRENT_SOURCE_DIR}/src/helper)

file(GLOB_RECURSE HELPER_SOURCE ${HELPER_SOURCE_DIR}/*.cpp)
list(FILTER HELPER_SOURCE EXCLUDE REGEX "^.*main\.cpp$")

add_library(helper_objects OBJECT ${HELPER_SOURCE})
set_target_properties(helper_objects PROPERTIES
    CXX_STANDARD 20
    CXX_STANDARD_REQUIRED YES
    POSITION_INDEPENDENT_CODE 1)
target_include_directories(helper_objects PUBLIC ${HELPER_INCLUDE_DIR})
target_compile_definitions(helper_objects PUBLIC SPDLOG_ACTIVE_LEVEL=SPDLOG_LEVEL_TRACE)
target_link_libraries(helper_objects PUBLIC libddwaf_objects pthread spdlog cpp-base64 msgpack_c RapidJSON::rapidjson Boost::system zlibstatic)

add_executable(ddappsec-helper src/helper/main.cpp
	$<TARGET_OBJECTS:helper_objects>
	$<TARGET_OBJECTS:libddwaf_objects>)
target_link_libraries(ddappsec-helper helper_objects) # for its PUBLIC deps

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
       #add_subdirectory(tests/bench_helper EXCLUDE_FROM_ALL)
       add_subdirectory(tests/fuzzer EXCLUDE_FROM_ALL)

       if(DD_APPSEC_ENABLE_COVERAGE)
           target_compile_options(helper_objects PRIVATE --coverage)
           target_compile_options(ddappsec_helper_test PRIVATE --coverage)

           target_link_options(ddappsec_helper_test PRIVATE --coverage)

           # helper objects are shared, so we need to link ddappsec-helper with --coverage too
           target_link_options(ddappsec-helper PRIVATE --coverage)
       endif()
endif()
