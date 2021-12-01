add_library(datadog-php-stack-sample OBJECT stack-sample.c stack-sample.h)

target_include_directories(datadog-php-stack-sample
  PUBLIC $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/../..>
)

target_compile_features(datadog-php-stack-sample PUBLIC c_std_11)

target_link_libraries(datadog-php-stack-sample PUBLIC datadog_php_string_view)

if (DATADOG_PHP_TESTING)
  add_subdirectory(tests)
endif ()
