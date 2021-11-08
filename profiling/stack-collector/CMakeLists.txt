add_library(datadog-php-stack-collector OBJECT stack-collector.c stack-collector.h)

target_include_directories(datadog-php-stack-collector
  INTERFACE $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/..>
)

target_compile_features(datadog-php-stack-collector
  INTERFACE c_std_99
  PRIVATE c_std_11
)

target_link_libraries(datadog-php-stack-collector
  PUBLIC datadog-php-stack-sample
  PRIVATE datadog-php-string-view PhpConfig::PhpConfig
)
