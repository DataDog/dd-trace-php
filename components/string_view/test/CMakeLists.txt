add_executable(string_view string_view.cc)

target_link_libraries(string_view
  PUBLIC catch2_main Datadog::PHP::StringView
)

catch_discover_tests(string_view)
