add_library(datadog-php-sapi OBJECT sapi.c sapi.h)

target_include_directories(datadog-php-sapi
  PUBLIC $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/..>
)

target_compile_features(datadog-php-sapi
  INTERFACE c_std_99
  PRIVATE c_std_11
)

target_link_libraries(datadog-php-sapi PUBLIC datadog-php-string-view)

if (DATADOG_PHP_TESTING)
  add_subdirectory(test)
endif ()
