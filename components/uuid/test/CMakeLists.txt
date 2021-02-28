add_executable(uuid uuid.cc)

target_link_libraries(uuid
  PUBLIC catch2_main Datadog::PHP::UUID
)

catch_discover_tests(uuid)
