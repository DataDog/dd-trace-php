add_executable(string_view string_view.cc)

target_link_libraries(string_view
  PUBLIC Catch2::Catch2WithMain Datadog::Php::StringView
)

catch_discover_tests(string_view)
