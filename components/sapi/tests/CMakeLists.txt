add_executable(sapi sapi.cc)

target_link_libraries(sapi
  PUBLIC Catch2::Catch2WithMain Datadog::Php::Sapi
)

catch_discover_tests(sapi)
