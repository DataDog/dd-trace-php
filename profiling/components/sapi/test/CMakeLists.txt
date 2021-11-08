add_executable(test-datadog-php-sapi sapi.cc)
target_link_libraries(test-datadog-php-sapi
  PRIVATE Catch2::Catch2WithMain datadog-php-sapi datadog-php-string-view
)

catch_discover_tests(test-datadog-php-sapi)
