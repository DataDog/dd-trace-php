add_library(DatadogPHPUUID uuid.c)

target_include_directories(DatadogPHPUUID
  PUBLIC
    $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
    $<INSTALL_INTERFACE:include>
)

target_compile_features(DatadogPHPUUID
  PUBLIC c_std_11
)

set_target_properties(DatadogPHPUUID PROPERTIES
  OUTPUT_NAME datadog_php_uuid
  VERSION ${PROJECT_VERSION}
)

add_library(Datadog::PHP::UUID
  ALIAS DatadogPHPUUID
)

if (${BUILD_COMPONENTS_TESTING})
  add_subdirectory(test)
endif()

# This copies the include files when `install` is ran
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/uuid.h
	DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/uuid/
)
