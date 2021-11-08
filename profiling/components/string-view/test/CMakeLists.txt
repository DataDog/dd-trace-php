add_executable(test-datadog-php-string-view string-view.cc)
target_link_libraries(test-datadog-php-string-view
  PRIVATE Catch2::Catch2WithMain datadog-php-string-view
)

catch_discover_tests(test-datadog-php-string-view)
