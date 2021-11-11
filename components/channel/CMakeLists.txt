add_library(datadog-php-channel channel.c)
target_include_directories(datadog-php-channel
  PUBLIC $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/..>
)

target_compile_features(datadog-php-channel
  INTERFACE c_std_99
  PRIVATE c_std_11
)

target_link_libraries(datadog-php-channel
  PRIVATE datadog-php-queue PkgConfig::UV
)

if (DATADOG_PHP_TESTING)
  add_subdirectory(test)
endif ()
