add_library(DatadogPHPStringView string_view.c)

target_include_directories(DatadogPHPStringView
  PUBLIC
    $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
    $<INSTALL_INTERFACE:include>
)

target_compile_features(DatadogPHPStringView
  PUBLIC c_std_99
)

set_target_properties(DatadogPHPStringView PROPERTIES
  OUTPUT_NAME datadog_php_string_view
  VERSION ${PROJECT_VERSION}
)

add_library(Datadog::PHP::StringView
  ALIAS DatadogPHPStringView
)

if (${BUILD_COMPONENTS_TESTING})
  add_subdirectory(test)
endif()

# This copies the include files when `install` is ran
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/string_view.h
	DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/string_view/
)
