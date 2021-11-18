add_executable(test-datadog-php-log log.cc)

find_package(Threads REQUIRED)

target_link_libraries(test-datadog-php-log
  PRIVATE Catch2::Catch2WithMain datadog-php-log datadog_php_string_view Threads::Threads
)

catch_discover_tests(test-datadog-php-log)
