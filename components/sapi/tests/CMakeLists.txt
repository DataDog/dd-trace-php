add_executable(sapi sapi.cc)

target_link_libraries(sapi
  PUBLIC catch2_main Datadog::Php::Sapi
)

catch_discover_tests(sapi)
