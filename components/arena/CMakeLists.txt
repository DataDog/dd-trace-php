add_library(datadog-php-arena OBJECT arena.c)

target_include_directories(datadog-php-arena
  PUBLIC $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/../..>
)

target_compile_features(datadog-php-arena
  INTERFACE c_std_99
  PRIVATE c_std_11
)

if (DATADOG_PHP_TESTING)
  add_subdirectory(tests)
endif ()
