add_executable(test-datadog-php-uuid uuid.cc)

target_link_libraries(test-datadog-php-uuid
  PUBLIC Catch2::Catch2WithMain Datadog::Php::Uuid
)

catch_discover_tests(test-datadog-php-uuid)
