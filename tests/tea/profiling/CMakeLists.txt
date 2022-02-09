add_library(tea-profiling SHARED profiling.cc profiling.h)
set_target_properties(tea-profiling PROPERTIES PREFIX "")
target_link_libraries(tea-profiling PUBLIC Tea::PHP datadog-php-stack-sample
                                           datadog_php_string_view)

add_executable(tea-profiling-interrupt-test
               interrupt.cc ${CMAKE_CURRENT_BINARY_DIR}/sleep.php)

target_link_libraries(
  tea-profiling-interrupt-test
  PUBLIC ext-tea-tests tea-profiling datadog-php-stack-sample
         datadog_php_string_view)

catch_discover_tests(tea-profiling-interrupt-test)

add_custom_command(
  OUTPUT ${CMAKE_CURRENT_BINARY_DIR}/sleep.php
  COMMAND ${CMAKE_COMMAND} -E copy ${CMAKE_CURRENT_SOURCE_DIR}/sleep.php
          ${CMAKE_CURRENT_BINARY_DIR}/sleep.php
  DEPENDS ${CMAKE_CURRENT_SOURCE_DIR}/sleep.php)
