hunter_add_package(Boost COMPONENTS system)
find_package(Boost CONFIG REQUIRED COMPONENTS system)

configure_file(src/helper/version.hpp.in ${CMAKE_CURRENT_SOURCE_DIR}/src/helper/version.hpp)

set(HELPER_SOURCE_DIR src/helper)
set(HELPER_INCLUDE_DIR ${CMAKE_CURRENT_SOURCE_DIR}/src/helper)

file(GLOB_RECURSE HELPER_SOURCE ${HELPER_SOURCE_DIR}/*.cpp)
list(FILTER HELPER_SOURCE EXCLUDE REGEX "^.*main\.cpp$")

add_library(helper_objects OBJECT ${HELPER_SOURCE})
set_target_properties(helper_objects PROPERTIES
    POSITION_INDEPENDENT_CODE 1)
target_include_directories(helper_objects PUBLIC ${HELPER_INCLUDE_DIR})
target_compile_definitions(helper_objects PUBLIC SPDLOG_ACTIVE_LEVEL=SPDLOG_LEVEL_TRACE)
target_link_libraries(helper_objects PUBLIC libddwaf_objects pthread spdlog cpp-base64 msgpack_c lib_rapidjson Boost::system)

add_executable(ddappsec-helper src/helper/main.cpp
	$<TARGET_OBJECTS:helper_objects>
	$<TARGET_OBJECTS:libddwaf_objects>)
target_link_libraries(ddappsec-helper helper_objects) # for its PUBLIC deps

try_compile(STDLIBXX_FS_NO_LIB_NEEDED ${CMAKE_CURRENT_BINARY_DIR}
    SOURCES ${CMAKE_CURRENT_SOURCE_DIR}/cmake/check_fslib.cpp
    CXX_STANDARD 17
    CXX_STANDARD_REQUIRED TRUE)
try_compile(STDLIBXX_FS_NEEDS_STDCXXFS ${CMAKE_CURRENT_BINARY_DIR}
    SOURCES ${CMAKE_CURRENT_SOURCE_DIR}/cmake/check_fslib.cpp
    CXX_STANDARD 17
    CXX_STANDARD_REQUIRED TRUE
    LINK_LIBRARIES stdc++fs)
try_compile(STDLIBXX_FS_NEEDS_CXXFS ${CMAKE_CURRENT_BINARY_DIR}
    SOURCES ${CMAKE_CURRENT_SOURCE_DIR}/cmake/check_fslib.cpp
    CXX_STANDARD 17
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

# Testing and examples
add_subdirectory(tests/helper EXCLUDE_FROM_ALL)
add_subdirectory(tests/bench_helper EXCLUDE_FROM_ALL)
add_subdirectory(tests/fuzzer EXCLUDE_FROM_ALL)
#IF(EXISTS "${CMAKE_CURRENT_SOURCE_DIR}/examples/")
    #add_subdirectory(examples)
#endif()

if(DD_APPSEC_ENABLE_COVERAGE)
    target_compile_options(helper_objects PRIVATE --coverage)
    target_compile_options(ddappsec_helper_test PRIVATE --coverage)

    target_link_options(ddappsec_helper_test PRIVATE --coverage)

    # helper objects are shared, so we need to link ddappsec-helper with --coverage too
    target_link_options(ddappsec-helper PRIVATE --coverage)
endif()

# Installation
install(TARGETS ddappsec-helper
	DESTINATION ${CMAKE_INSTALL_BINDIR})
split_debug(ddappsec-helper ${CMAKE_INSTALL_BINDIR})

if(DD_APPSEC_INSTALL_RULES_FILE STREQUAL "")
    ExternalProject_Get_property(event_rules SOURCE_DIR)
    set(EVENT_RULES_SOURCE_DIR ${SOURCE_DIR})
    add_custom_target(rules_json ALL true
        DEPENDS ${EVENT_RULES_SOURCE_DIR}/build/recommended.json)
    add_dependencies(rules_json event_rules)
    install(FILES ${EVENT_RULES_SOURCE_DIR}/build/recommended.json
        DESTINATION ${CMAKE_INSTALL_SYSCONFDIR}/dd-appsec/)
else()
    install(FILES ${DD_APPSEC_INSTALL_RULES_FILE}
        DESTINATION ${CMAKE_INSTALL_SYSCONFDIR}/dd-appsec/)
endif()

