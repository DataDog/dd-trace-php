enable_language(CXX)

# The Catch2::Catch2 target has been available since 2.1.2
# dd-trace-cpp uses Catch2 2.4 at this time, so we can require at least 2.4
find_package(Catch2 2.4 REQUIRED)

include(Catch)

add_library(catch2main main.cc)
target_link_libraries(catch2main PUBLIC Catch2::Catch2)
target_compile_features(catch2main PUBLIC cxx_std_11)

#[[
  DatadogString
#]]
add_executable(string string.cc)
target_link_libraries(string PUBLIC catch2main Datadog::String)
catch_discover_tests(string)

#[[
  DatadogContainerId
#]]
add_executable(container_id container_id.cc)
target_link_libraries(container_id PUBLIC catch2main Datadog::String Datadog::ContainerId)
file(
  COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
  DESTINATION ${CMAKE_CURRENT_BINARY_DIR}
)
catch_discover_tests(container_id)
