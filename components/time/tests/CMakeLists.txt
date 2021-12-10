add_executable(test-datadog-php-time time.cc)

target_link_libraries(test-datadog-php-time
  PRIVATE Catch2::Catch2WithMain datadog-php-time
)

catch_discover_tests(test-datadog-php-time)
