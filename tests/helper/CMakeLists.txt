include(GoogleTest)

file(GLOB_RECURSE DDAPPSEC_TEST_SOURCE *.cpp)
add_executable(ddappsec_helper_test ${DDAPPSEC_TEST_SOURCE})
target_link_libraries(ddappsec_helper_test
    PRIVATE helper_objects libddwaf_objects pthread spdlog gtest gmock)

target_include_directories(ddappsec_helper_test PRIVATE ${CMAKE_SOURCE_DIR}/third_party/)
gtest_discover_tests(ddappsec_helper_test WORKING_DIRECTORY ${ddappsec_SOURCE_DIR}/tests/helper)
