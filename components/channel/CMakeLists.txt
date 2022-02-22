add_library(datadog-php-channel channel.c)
target_include_directories(datadog-php-channel
  PUBLIC $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/../..>
)

target_compile_features(datadog-php-channel
  INTERFACE c_std_99
  PRIVATE c_std_11
)

# libuv's pc file doesn't put -pthread into its link line, but it depends on it
# for all our current platforms.
find_package(Threads REQUIRED)
target_link_libraries(datadog-php-channel
  PRIVATE datadog-php-queue PkgConfig::UV Threads::Threads
)

if (DATADOG_PHP_TESTING)
  add_subdirectory(tests)
endif ()
