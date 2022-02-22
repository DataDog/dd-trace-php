add_library(datadog-php-queue queue.c)
target_include_directories(datadog-php-queue
  PUBLIC $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/../..>
)

target_compile_features(datadog-php-queue
  INTERFACE c_std_99
  PRIVATE c_std_11
)

if (DATADOG_PHP_TESTING)
  add_subdirectory(tests)
endif ()
