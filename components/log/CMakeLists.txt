add_library(datadog-php-log OBJECT log.c)

target_include_directories(datadog-php-log
  PUBLIC $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/../..>
)

target_compile_features(datadog-php-log
  INTERFACE c_std_99
  PRIVATE c_std_11
)

target_link_libraries(datadog-php-log PUBLIC datadog_php_string_view)

if (DATADOG_PHP_TESTING)
  add_subdirectory(tests)
endif ()
