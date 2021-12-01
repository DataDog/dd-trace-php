add_library(datadog_php_container_id container_id.c)

target_include_directories(datadog_php_container_id
  PUBLIC
    $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/../..>
    $<INSTALL_INTERFACE:include>
)

target_compile_features(datadog_php_container_id
  PUBLIC c_std_99
)

set_target_properties(datadog_php_container_id PROPERTIES
  EXPORT_NAME ContainerId
  VERSION ${PROJECT_VERSION}
)

add_library(Datadog::Php::ContainerId
  ALIAS datadog_php_container_id
)

if (${DATADOG_PHP_TESTING})
  add_subdirectory(tests)
endif ()

# This copies the include files when `install` is ran
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/container_id.h
  DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/container_id/
)

target_link_libraries(datadog_php_components
  INTERFACE datadog_php_container_id
)

install(TARGETS datadog_php_container_id
  EXPORT DatadogPhpComponentsTargets
)
