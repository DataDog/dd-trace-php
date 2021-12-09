add_library(datadog-php-uuid uuid.c)

target_include_directories(datadog-php-uuid
  PUBLIC
    $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/../..>
    $<INSTALL_INTERFACE:include>
)

target_compile_features(datadog-php-uuid
  PUBLIC c_std_11
)

set_target_properties(datadog-php-uuid PROPERTIES
  EXPORT_NAME Uuid
  VERSION ${PROJECT_VERSION}
)

add_library(Datadog::Php::Uuid
  ALIAS datadog-php-uuid
)

if (DATADOG_PHP_TESTING)
  add_subdirectory(tests)
endif ()

# This copies the include files when `install` is ran
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/uuid.h
  DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/uuid/
)

target_link_libraries(datadog_php_components
  INTERFACE datadog-php-uuid
)

install(TARGETS datadog-php-uuid
  EXPORT DatadogPhpComponentsTargets
)
