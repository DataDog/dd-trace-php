add_executable(test-datadog-php-arena arena.cc)
target_link_libraries(test-datadog-php-arena
  PRIVATE Catch2::Catch2WithMain datadog-php-arena
)

catch_discover_tests(test-datadog-php-arena)
