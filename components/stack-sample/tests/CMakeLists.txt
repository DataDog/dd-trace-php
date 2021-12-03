add_executable(test-datadog-php-stack-sample stack-sample.cc)
target_link_libraries(test-datadog-php-stack-sample
  PRIVATE Catch2::Catch2WithMain datadog-php-stack-sample datadog_php_string_view
)

catch_discover_tests(test-datadog-php-stack-sample)
