add_library(datadog-php-string-view OBJECT string-view.c)

target_include_directories(datadog-php-string-view
  PUBLIC $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/..>
)

target_compile_features(datadog-php-string-view
  INTERFACE c_std_99
  PRIVATE c_std_11
)

if (DATADOG_PHP_TESTING)
  add_subdirectory(test)
endif ()
