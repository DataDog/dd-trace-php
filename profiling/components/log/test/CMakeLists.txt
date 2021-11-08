add_executable(test-datadog-php-log log.cc)
target_link_libraries(test-datadog-php-log
  PRIVATE Catch2::Catch2WithMain datadog-php-log datadog-php-string-view
)

catch_discover_tests(test-datadog-php-log)
