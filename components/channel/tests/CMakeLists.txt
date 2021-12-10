add_executable(test-datadog-php-channel channel.cc)

target_link_libraries(test-datadog-php-channel
  PRIVATE Catch2::Catch2WithMain datadog-php-channel PkgConfig::UV
)

# Channels have deadlock opportunities, so set a timeout
catch_discover_tests(test-datadog-php-channel PROPERTIES TIMEOUT 20)