add_executable(container_id
  container_id_from_file.cc
  container_id_parser.cc
)

target_link_libraries(container_id
  PUBLIC Catch2::Catch2WithMain Datadog::Php::ContainerId
)

file(
  COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
  DESTINATION ${CMAKE_CURRENT_BINARY_DIR}
)

catch_discover_tests(container_id)
