add_library(datadog_php_sapi sapi.c)

target_include_directories(datadog_php_sapi
  PUBLIC
    $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/../..>
    $<INSTALL_INTERFACE:include>
)

target_compile_features(datadog_php_sapi
  PUBLIC c_std_99
)

set_target_properties(datadog_php_sapi PROPERTIES
  EXPORT_NAME Sapi
  VERSION ${PROJECT_VERSION}
)

target_link_libraries(datadog_php_sapi
  PUBLIC Datadog::Php::StringView
)

add_library(Datadog::Php::Sapi
  ALIAS datadog_php_sapi
)

if (${DATADOG_PHP_TESTING})
  add_subdirectory(tests)
endif ()

# This copies the include files when `install` is ran
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/sapi.h
  DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/sapi/
)

target_link_libraries(datadog_php_components
  INTERFACE datadog_php_sapi
)

install(TARGETS datadog_php_sapi
  EXPORT DatadogPhpComponentsTargets
)
