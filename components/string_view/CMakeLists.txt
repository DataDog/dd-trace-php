add_library(datadog_php_string_view string_view.c)

target_include_directories(datadog_php_string_view
  PUBLIC
    $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/../..>
    $<INSTALL_INTERFACE:include>
)

target_compile_features(datadog_php_string_view
  PUBLIC c_std_99
)

set_target_properties(datadog_php_string_view PROPERTIES
  EXPORT_NAME StringView
  VERSION ${PROJECT_VERSION}
)

add_library(Datadog::Php::StringView
  ALIAS datadog_php_string_view
)

if (${DATADOG_PHP_TESTING})
  add_subdirectory(tests)
endif ()

# This copies the include files when `install` is ran
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/string_view.h
  DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/string_view/
)

target_link_libraries(datadog_php_components
  INTERFACE datadog_php_string_view
)

install(TARGETS datadog_php_string_view
  EXPORT DatadogPhpComponentsTargets
)
