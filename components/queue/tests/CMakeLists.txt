add_executable(test-datadog-php-queue queue.cc)
target_link_libraries(test-datadog-php-queue
  PRIVATE Catch2::Catch2WithMain datadog-php-queue
)

catch_discover_tests(test-datadog-php-queue)
