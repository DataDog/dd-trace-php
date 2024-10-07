file(GLOB_RECURSE DDAPPSEC_TEST_SOURCE *.cpp)
add_executable(ddappsec_helper_test ${DDAPPSEC_TEST_SOURCE})
target_link_libraries(ddappsec_helper_test
    PRIVATE helper_objects libddwaf_objects pthread spdlog gtest gmock)

target_include_directories(ddappsec_helper_test PRIVATE ${CMAKE_SOURCE_DIR}/third_party/)

if(${CMAKE_SYSTEM_NAME} STREQUAL "Linux")
    target_link_options(ddappsec_helper_test PRIVATE -Wl,--export-dynamic)
endif()
